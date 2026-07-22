<?php

// A story file with no matching Twig template → MISSING_TEMPLATE error,
// but must not break the rest of the scan.
return [
    'Default' => ['foo' => 'bar'],
];
