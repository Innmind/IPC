<?php
declare(strict_types = 1);

use Innmind\IPC\Message;
use Fixtures\Innmind\MediaType\MediaType;
use Innmind\Immutable\Str;
use Innmind\BlackBox\Set;

return static function() {
    yield proof(
        'Message::equals()',
        given(
            MediaType::any(),
            MediaType::any(),
            Set::strings()->map(Str::of(...)),
            Set::strings()->map(Str::of(...)),
        ),
        static function($assert, $mediaTypeA, $mediaTypeB, $a, $b) {
            $assert->true(
                Message::of($mediaTypeA, $a)->equals(
                    Message::of($mediaTypeA, $a),
                ),
            );
            $assert->false(
                Message::of($mediaTypeA, $a)->equals(
                    Message::of($mediaTypeA, $b),
                ),
            );
            $assert->false(
                Message::of($mediaTypeA, $a)->equals(
                    Message::of($mediaTypeB, $a),
                ),
            );
            $assert->false(
                Message::of($mediaTypeA, $a)->equals(
                    Message::of($mediaTypeB, $b),
                ),
            );
        },
    );
};
