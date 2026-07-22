<?php

// Rich format: meta + explicit stories.
return [
    'meta' => [
        'title' => 'Button',
        'group' => 'Atoms',
        'description' => 'Primary user-action button.',
        'status' => 'stable',
    ],
    'stories' => [
        'Primary' => [
            'args' => ['label' => 'Save', 'variant' => 'primary', 'disabled' => false],
            'description' => 'The default call to action.',
            'tags' => ['action', 'form'],
        ],
        'Secondary' => [
            'args' => ['label' => 'Cancel', 'variant' => 'secondary', 'disabled' => false],
        ],
        'Disabled' => [
            'args' => ['label' => 'Unavailable', 'variant' => 'primary', 'disabled' => true],
        ],
    ],
];
