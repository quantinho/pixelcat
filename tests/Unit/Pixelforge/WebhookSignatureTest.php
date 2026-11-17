<?php

use App\Pixelforge\WebhookSignature;

const WEBHOOK_TEST_SECRET = 'whsec_testsecret';
const WEBHOOK_TEST_SIGNED_AT = 1_795_000_000;

beforeEach(function () {
    $this->payload = '{"id":"evt_123","object":"event","type":"generation.succeeded","data":{"object":{"id":"gen_abc"}}}';
    $this->signature = new WebhookSignature(WEBHOOK_TEST_SECRET);
    // Computed independently of WebhookSignature::sign(), as the docs describe it.
    $this->header = 't='.WEBHOOK_TEST_SIGNED_AT.',v1='.hash_hmac('sha256', WEBHOOK_TEST_SIGNED_AT.'.'.$this->payload, WEBHOOK_TEST_SECRET);
});

describe('sign', function () {
    it('builds "t=…,v1=…" with the hex HMAC-SHA256 of "{t}.{raw body}"', function () {
        expect(WebhookSignature::sign($this->payload, WEBHOOK_TEST_SECRET, WEBHOOK_TEST_SIGNED_AT))->toBe($this->header);
    });
});

describe('verify', function () {
    it('accepts a valid signature', function () {
        expect($this->signature->verify($this->header, $this->payload, WEBHOOK_TEST_SIGNED_AT))->toBeTrue();
    });

    it('accepts what sign() produces', function () {
        $header = WebhookSignature::sign($this->payload, WEBHOOK_TEST_SECRET, WEBHOOK_TEST_SIGNED_AT);

        expect($this->signature->verify($header, $this->payload, WEBHOOK_TEST_SIGNED_AT + 10))->toBeTrue();
    });

    it('rejects a signature made with another secret', function () {
        $header = WebhookSignature::sign($this->payload, 'whsec_othersecret', WEBHOOK_TEST_SIGNED_AT);

        expect($this->signature->verify($header, $this->payload, WEBHOOK_TEST_SIGNED_AT))->toBeFalse();
    });

    it('rejects a tampered body', function (string $tampered) {
        expect($this->signature->verify($this->header, $tampered, WEBHOOK_TEST_SIGNED_AT))->toBeFalse();
    })->with([
        'changed value' => ['{"id":"evt_123","object":"event","type":"generation.failed","data":{"object":{"id":"gen_abc"}}}'],
        'reformatted JSON' => ['{"id": "evt_123", "object": "event", "type": "generation.succeeded", "data": {"object": {"id": "gen_abc"}}}'],
        'trailing newline' => ["{\"id\":\"evt_123\",\"object\":\"event\",\"type\":\"generation.succeeded\",\"data\":{\"object\":{\"id\":\"gen_abc\"}}}\n"],
        'empty' => [''],
    ]);

    it('rejects a timestamp more than 300 s from now', function (int $now) {
        expect($this->signature->verify($this->header, $this->payload, $now))->toBeFalse();
    })->with([
        'signed 301 s ago' => [WEBHOOK_TEST_SIGNED_AT + 301],
        'signed 301 s in the future' => [WEBHOOK_TEST_SIGNED_AT - 301],
        'signed an hour ago' => [WEBHOOK_TEST_SIGNED_AT + 3600],
    ]);

    it('accepts a timestamp exactly 300 s from now', function (int $now) {
        expect($this->signature->verify($this->header, $this->payload, $now))->toBeTrue();
    })->with([
        'signed 300 s ago' => [WEBHOOK_TEST_SIGNED_AT + 300],
        'signed 300 s in the future' => [WEBHOOK_TEST_SIGNED_AT - 300],
    ]);

    it('rejects a valid v1 moved to another timestamp', function () {
        // An attacker replays an old signature with a fresh "t".
        $v1 = hash_hmac('sha256', WEBHOOK_TEST_SIGNED_AT.'.'.$this->payload, WEBHOOK_TEST_SECRET);
        $header = 't='.(WEBHOOK_TEST_SIGNED_AT + 1000).",v1={$v1}";

        expect($this->signature->verify($header, $this->payload, WEBHOOK_TEST_SIGNED_AT + 1000))->toBeFalse();
    });

    it('rejects a malformed header', function (string $header) {
        expect($this->signature->verify($header, $this->payload, WEBHOOK_TEST_SIGNED_AT))->toBeFalse();
    })->with(function () {
        $v1 = hash_hmac('sha256', WEBHOOK_TEST_SIGNED_AT.'.{"id":"evt_123","object":"event","type":"generation.succeeded","data":{"object":{"id":"gen_abc"}}}', WEBHOOK_TEST_SECRET);

        return [
            'empty' => [''],
            'garbage' => ['not a signature'],
            'no timestamp' => ["v1={$v1}"],
            'no signature' => ['t='.WEBHOOK_TEST_SIGNED_AT],
            'empty signature' => ['t='.WEBHOOK_TEST_SIGNED_AT.',v1='],
            'non-numeric timestamp' => ["t=yesterday,v1={$v1}"],
            'truncated signature' => ['t='.WEBHOOK_TEST_SIGNED_AT.',v1='.substr($v1, 0, 63)],
            'unknown scheme only' => ['t='.WEBHOOK_TEST_SIGNED_AT.",v0={$v1}"],
            'signature without "t=" prefix' => [WEBHOOK_TEST_SIGNED_AT.",{$v1}"],
        ];
    });

    it('uses the current time when none is given', function () {
        $now = time();

        expect($this->signature->verify(WebhookSignature::sign($this->payload, WEBHOOK_TEST_SECRET, $now), $this->payload))->toBeTrue()
            ->and($this->signature->verify(WebhookSignature::sign($this->payload, WEBHOOK_TEST_SECRET, $now - 400), $this->payload))->toBeFalse();
    });

    it('honours a custom tolerance', function () {
        $strict = new WebhookSignature(WEBHOOK_TEST_SECRET, 60);

        expect($strict->verify($this->header, $this->payload, WEBHOOK_TEST_SIGNED_AT + 60))->toBeTrue()
            ->and($strict->verify($this->header, $this->payload, WEBHOOK_TEST_SIGNED_AT + 61))->toBeFalse();
    });
});
