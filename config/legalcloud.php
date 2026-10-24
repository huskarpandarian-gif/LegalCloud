<?php

return [
    'api_mode' => env('LEGALCLOUD_API_MODE', 'api'),
    'api_token_ttl_days' => (int) env('LEGALCLOUD_API_TOKEN_TTL_DAYS', 30),
    'documents' => [
        'disk' => env('LEGALCLOUD_DOCUMENTS_DISK', 'private'),
    ],
    'ai' => [
        'enabled' => (bool) env('LEGALCLOUD_LLM_ENABLED', false),
        'provider' => env('LEGALCLOUD_LLM_PROVIDER', 'openai'),
    ],
    'ocr' => [
        'provider' => env('LEGALCLOUD_OCR_PROVIDER', 'native'),
    ],
    'embeddings' => [
        'enabled' => (bool) env('LEGALCLOUD_EMBEDDINGS_ENABLED', false),
        'provider' => env('LEGALCLOUD_EMBEDDINGS_PROVIDER', 'openai'),
    ],
    'vector' => [
        'enabled' => (bool) env('VECTOR_DB_ENABLED', false),
        'connection' => env('VECTOR_DB_CONNECTION', 'vector'),
        'dimensions' => (int) env('VECTOR_EMBEDDING_DIMENSIONS', 1536),
        'embedding_model' => env('VECTOR_EMBEDDING_MODEL', 'text-embedding-3-small'),
    ],
    'permissions' => [
        'users.manage',
        'cases.read',
        'cases.write',
        'documents.read',
        'documents.write',
        'documents.process',
        'categories.read',
        'categories.manage',
        'search.use',
        'assistant.use',
        'audit.read',
        'notifications.read',
    ],
    'roles' => [
        'administrator' => [
            'label' => 'Administrador',
            'home' => '/dashboard',
            'permissions' => ['users.manage', 'cases.read', 'cases.write', 'documents.read', 'documents.write', 'documents.process', 'categories.read', 'categories.manage', 'search.use', 'assistant.use', 'audit.read', 'notifications.read'],
        ],
        'records_manager' => [
            'label' => 'Responsable documental',
            'home' => '/dashboard',
            'permissions' => ['cases.read', 'cases.write', 'documents.read', 'documents.write', 'documents.process', 'categories.read', 'search.use', 'assistant.use', 'audit.read', 'notifications.read'],
        ],
        'analyst' => [
            'label' => 'Especialista / analista',
            'home' => '/dashboard',
            'permissions' => ['cases.read', 'cases.write', 'documents.read', 'documents.write', 'documents.process', 'categories.read', 'search.use', 'assistant.use', 'notifications.read'],
        ],
        'consultant' => [
            'label' => 'Usuario consultor',
            'home' => '/dashboard',
            'permissions' => ['cases.read', 'documents.read', 'categories.read', 'search.use', 'assistant.use', 'notifications.read'],
        ],
    ],
];