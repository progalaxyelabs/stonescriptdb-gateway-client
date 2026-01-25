<?php

namespace StoneScriptDB\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class TokenValidator
{
    private const CACHE_TTL = 3600; // 1 hour in seconds

    private ?string $cachedPublicKey = null;
    private ?int $cacheExpiry = null;

    public function __construct(
        private readonly string $gatewayBaseUrl
    ) {}

    /**
     * Validate a JWT token and return the claims
     *
     * @param string $jwt The JWT token to validate
     * @return TokenClaims The validated token claims
     * @throws InvalidTokenException If the token is invalid, expired, or has an invalid signature
     */
    public function validateToken(string $jwt): TokenClaims
    {
        try {
            $publicKey = $this->getPublicKey();

            // Decode and validate the JWT
            $decoded = JWT::decode($jwt, new Key($publicKey, 'RS256'));

            // Convert stdClass to array
            $payload = (array) $decoded;

            // Create TokenClaims from the payload
            $claims = TokenClaims::fromPayload($payload);

            // Check if token is expired
            if ($claims->isExpired()) {
                throw new InvalidTokenException('Token has expired');
            }

            return $claims;

        } catch (InvalidTokenException $e) {
            throw $e;
        } catch (\Firebase\JWT\ExpiredException $e) {
            throw new InvalidTokenException('Token has expired: ' . $e->getMessage(), 0, $e);
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            throw new InvalidTokenException('Invalid token signature: ' . $e->getMessage(), 0, $e);
        } catch (\Firebase\JWT\BeforeValidException $e) {
            throw new InvalidTokenException('Token not yet valid: ' . $e->getMessage(), 0, $e);
        } catch (Exception $e) {
            throw new InvalidTokenException('Token validation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the public key from cache or fetch from gateway
     *
     * @return string The public key in PEM format
     * @throws InvalidTokenException If the public key cannot be fetched
     */
    public function getPublicKey(): string
    {
        // Check if cached key is still valid
        if ($this->cachedPublicKey !== null &&
            $this->cacheExpiry !== null &&
            time() < $this->cacheExpiry) {
            return $this->cachedPublicKey;
        }

        // Fetch new public key
        $this->refreshPublicKey();

        return $this->cachedPublicKey;
    }

    /**
     * Refresh the public key from the gateway JWKS endpoint
     *
     * @return void
     * @throws InvalidTokenException If the JWKS endpoint cannot be reached or returns invalid data
     */
    public function refreshPublicKey(): void
    {
        $jwksUrl = rtrim($this->gatewayBaseUrl, '/') . '/auth/jwks';

        try {
            // Initialize cURL
            $ch = curl_init($jwksUrl);
            if ($ch === false) {
                throw new InvalidTokenException('Failed to initialize cURL');
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new InvalidTokenException("Failed to fetch JWKS: $error");
            }

            if ($httpCode !== 200) {
                throw new InvalidTokenException("JWKS endpoint returned HTTP $httpCode");
            }

            $jwks = json_decode($response, true);

            if (!is_array($jwks) || !isset($jwks['keys']) || !is_array($jwks['keys']) || empty($jwks['keys'])) {
                throw new InvalidTokenException('Invalid JWKS response format');
            }

            // Get the first key (assumes single key in JWKS)
            $key = $jwks['keys'][0];

            // Convert JWK to PEM format
            $publicKey = $this->jwkToPem($key);

            // Cache the public key
            $this->cachedPublicKey = $publicKey;
            $this->cacheExpiry = time() + self::CACHE_TTL;

        } catch (InvalidTokenException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new InvalidTokenException('Failed to refresh public key: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Convert JWK to PEM format
     *
     * @param array $jwk The JWK data
     * @return string The public key in PEM format
     * @throws InvalidTokenException If JWK format is invalid
     */
    private function jwkToPem(array $jwk): string
    {
        if (!isset($jwk['kty']) || $jwk['kty'] !== 'RSA') {
            throw new InvalidTokenException('Only RSA keys are supported');
        }

        if (!isset($jwk['n']) || !isset($jwk['e'])) {
            throw new InvalidTokenException('Invalid JWK format: missing n or e');
        }

        // Decode base64url-encoded values
        $n = $this->base64UrlDecode($jwk['n']);
        $e = $this->base64UrlDecode($jwk['e']);

        // Build the RSA public key
        $rsa = [
            'modulus' => new \phpseclib3\Math\BigInteger($n, 256),
            'publicExponent' => new \phpseclib3\Math\BigInteger($e, 256),
        ];

        // Use phpseclib3 if available, otherwise use manual PEM construction
        if (class_exists('\phpseclib3\Crypt\RSA')) {
            $publicKey = \phpseclib3\Crypt\PublicKeyLoader::load($rsa);
            return $publicKey->toString('PKCS8');
        }

        // Fallback: Manual PEM construction using OpenSSL
        // This is a simplified approach - in production you might want phpseclib3
        $modulus = $this->base64UrlDecode($jwk['n']);
        $exponent = $this->base64UrlDecode($jwk['e']);

        // ASN.1 DER encoding for RSA public key
        $der = $this->createRsaPublicKeyDer($modulus, $exponent);
        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($der), 64, "\n");
        $pem .= "-----END PUBLIC KEY-----";

        return $pem;
    }

    /**
     * Create ASN.1 DER encoding for RSA public key
     *
     * @param string $modulus The modulus bytes
     * @param string $exponent The exponent bytes
     * @return string The DER-encoded public key
     */
    private function createRsaPublicKeyDer(string $modulus, string $exponent): string
    {
        // This is a simplified DER encoder for RSA public keys
        // Format: SEQUENCE { SEQUENCE { OID, NULL }, BIT STRING { SEQUENCE { INTEGER(n), INTEGER(e) } } }

        // Encode integers
        $modulusInt = $this->derInteger($modulus);
        $exponentInt = $this->derInteger($exponent);

        // SEQUENCE of modulus and exponent
        $rsaSequence = $this->derSequence($modulusInt . $exponentInt);

        // BIT STRING containing the RSA sequence
        $bitString = "\x03" . $this->derLength(strlen($rsaSequence) + 1) . "\x00" . $rsaSequence;

        // Algorithm identifier (RSA OID)
        $rsaOid = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"; // 1.2.840.113549.1.1.1
        $null = "\x05\x00";
        $algorithmId = $this->derSequence($rsaOid . $null);

        // Final SEQUENCE
        return $this->derSequence($algorithmId . $bitString);
    }

    /**
     * Create DER INTEGER from bytes
     */
    private function derInteger(string $bytes): string
    {
        // Add padding if high bit is set (to keep it positive)
        if (ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . $this->derLength(strlen($bytes)) . $bytes;
    }

    /**
     * Create DER SEQUENCE
     */
    private function derSequence(string $contents): string
    {
        return "\x30" . $this->derLength(strlen($contents)) . $contents;
    }

    /**
     * Create DER length encoding
     */
    private function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $encoded = '';
        $temp = $length;
        while ($temp > 0) {
            $encoded = chr($temp & 0xff) . $encoded;
            $temp >>= 8;
        }

        return chr(0x80 | strlen($encoded)) . $encoded;
    }

    /**
     * Decode base64url-encoded string
     *
     * @param string $input The base64url-encoded string
     * @return string The decoded bytes
     */
    private function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }
}
