<?php
declare(strict_types = 1);

use Innmind\IPC\Message;
use Fixtures\Innmind\MediaType\MediaType;
use Innmind\Immutable\Str;
use Innmind\BlackBox\Set;

return static function() {
    yield proof(
        'Message::equals() depends on the media type',
        given(
            MediaType::any(),
            MediaType::any(),
            Set::strings()->map(Str::of(...)),
        ),
        static function($assert, $mediaTypeA, $mediaTypeB, $content) {
            $assert->true(
                Message::of($mediaTypeA, $content)->equals(
                    Message::of($mediaTypeA, $content),
                ),
            );
            $assert->false(
                Message::of($mediaTypeA, $content)->equals(
                    Message::of($mediaTypeB, $content),
                ),
            );
        },
    );

    yield proof(
        'Message::equals()',
        given(
            MediaType::any(),
            Set::strings()->map(Str::of(...)),
            Set::strings()->map(Str::of(...)),
        )->filter(static fn($_, $a, $b) => !$a->equals($b)),
        static function($assert, $mediaType, $a, $b) {
            $assert->true(
                Message::of($mediaType, $a)->equals(
                    Message::of($mediaType, $a),
                ),
            );
            $assert->true(
                Message::of($mediaType, $b)->equals(
                    Message::of($mediaType, $b),
                ),
            );
            $assert->false(
                Message::of($mediaType, $a)->equals(
                    Message::of($mediaType, $b),
                ),
            );
        },
    );
};
