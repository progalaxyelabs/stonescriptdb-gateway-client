<?php
// Test double: a gateway that answers every call with a PostgreSQL-shaped error echoing values.
http_response_code(500);
header('Content-Type: application/json');
echo json_encode([
    'error' => 'query_failed',
    'message' => "Query for function 'create_user' failed for jane.sentinel@example.test",
    'database' => 'tenant_db',
    'cause' => 'duplicate key value violates unique constraint "users_email_key" | DETAIL: Key (email)=(jane.sentinel@example.test) already exists. | HINT: try +919876500123 | COLUMN: email | TABLE: users | SCHEMA: public',
]);
