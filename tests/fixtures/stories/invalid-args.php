<?php

// "Good" resolves normally; "Bad" has non-array args and must be skipped with
// an INVALID_STORY_FORMAT error, without dropping "Good".
return [
    'Good' => ['label' => 'OK'],
    'Bad' => 'not-an-array',
];
