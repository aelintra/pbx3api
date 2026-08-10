<?php

use App\Support\ExtLenPolicy;

test('normalize clamps and defaults', function () {
    expect(ExtLenPolicy::normalize(null))->toBe(3)
        ->and(ExtLenPolicy::normalize(''))->toBe(3)
        ->and(ExtLenPolicy::normalize(4))->toBe(4)
        ->and(ExtLenPolicy::normalize(1))->toBe(3)
        ->and(ExtLenPolicy::normalize(9))->toBe(3)
        ->and(ExtLenPolicy::normalize('2'))->toBe(2);
});

test('extension pkey must be exactly ext_len digits', function () {
    expect(ExtLenPolicy::isValidExtensionPkey('100', 3))->toBeTrue()
        ->and(ExtLenPolicy::isValidExtensionPkey('1000', 3))->toBeFalse()
        ->and(ExtLenPolicy::isValidExtensionPkey('1000', 4))->toBeTrue()
        ->and(ExtLenPolicy::isValidExtensionPkey('10a', 3))->toBeFalse()
        ->and(ExtLenPolicy::isValidExtensionPkey('', 3))->toBeFalse();
});

test('minMatchLength for Asterisk patterns', function () {
    expect(ExtLenPolicy::minMatchLength('_0.'))->toBe(2)
        ->and(ExtLenPolicy::minMatchLength('_00.'))->toBe(3)
        ->and(ExtLenPolicy::minMatchLength('_0XXX.'))->toBe(5)
        ->and(ExtLenPolicy::minMatchLength('_00XX.'))->toBe(5)
        ->and(ExtLenPolicy::minMatchLength('_9.'))->toBe(2)
        ->and(ExtLenPolicy::minMatchLength('_9XXXX'))->toBe(5)
        ->and(ExtLenPolicy::minMatchLength('100'))->toBe(3)
        ->and(ExtLenPolicy::minMatchLength('_X!'))->toBe(1);
});

test('dialplanError enforces min match greater than ext_len', function () {
    expect(ExtLenPolicy::dialplanError('_0. _00.', 3))->not->toBeNull()
        ->and(ExtLenPolicy::dialplanError('_0XXX. _00XX.', 3))->toBeNull()
        ->and(ExtLenPolicy::dialplanError('_9.', 3))->not->toBeNull()
        ->and(ExtLenPolicy::dialplanError('_9XXXX', 3))->toBeNull()
        ->and(ExtLenPolicy::dialplanError(ExtLenPolicy::UK_SEED_DIALPLAN, ExtLenPolicy::DEFAULT))->toBeNull();
});

test('shortDialLengthOk requires prefix plus dest longer than caller', function () {
    expect(ExtLenPolicy::shortDialLengthOk(2, 3, 3))->toBeTrue() // 5 > 3
        ->and(ExtLenPolicy::shortDialLengthOk(2, 3, 5))->toBeFalse() // 5 <= 5
        ->and(ExtLenPolicy::shortDialLengthOk(2, 4, 5))->toBeTrue(); // 6 > 5
});

test('remainderMask repeats X', function () {
    expect(ExtLenPolicy::remainderMask(4))->toBe('XXXX')
        ->and(ExtLenPolicy::remainderMask(3))->toBe('XXX');
});
