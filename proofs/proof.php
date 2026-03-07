<?php
declare(strict_types = 1);

return static function() {
    yield test(
        'placeholder',
        static fn($assert) => $assert->true(true),
    );
};
