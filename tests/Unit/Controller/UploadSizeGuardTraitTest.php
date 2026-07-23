<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\UploadSizeGuardTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class UploadSizeGuardTraitTest extends TestCase
{
    private string $originalPostMaxSize;

    protected function setUp(): void
    {
        $this->originalPostMaxSize = (string) ini_get('post_max_size');
    }

    protected function tearDown(): void
    {
        ini_set('post_max_size', $this->originalPostMaxSize);
    }

    public function testDetectsRequestLargerThanPostMaxSize(): void
    {
        ini_set('post_max_size', '8M');

        $request = Request::create('/', 'POST', server: ['CONTENT_LENGTH' => (string) (9 * 1024 * 1024)]);

        self::assertTrue($this->subject()->check($request));
    }

    public function testAllowsRequestWithinPostMaxSize(): void
    {
        ini_set('post_max_size', '8M');

        $request = Request::create('/', 'POST', server: ['CONTENT_LENGTH' => (string) (1 * 1024 * 1024)]);

        self::assertFalse($this->subject()->check($request));
    }

    public function testIgnoresRequestsWithoutContentLengthHeader(): void
    {
        ini_set('post_max_size', '8M');

        $request = Request::create('/', 'POST');
        $request->headers->remove('Content-Length');

        self::assertFalse($this->subject()->check($request));
    }

    private function subject(): object
    {
        return new class {
            use UploadSizeGuardTrait;

            public function check(Request $request): bool
            {
                return $this->isUploadTooLarge($request);
            }
        };
    }
}
