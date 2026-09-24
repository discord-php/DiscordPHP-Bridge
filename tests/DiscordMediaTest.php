<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-Bridge project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Bridge\Tests;

use Bridge\Support\DiscordMedia;
use PHPUnit\Framework\TestCase;

/**
 * Which links are pictures on Discord's CDN, and in what format to pass them on.
 */
final class DiscordMediaTest extends TestCase
{
    public function testAnAnimatedWebpFromAnImageEndpointIsAskedForAsAGif(): void
    {
        // What the GIF picker sent for a favourited banner. As a WebP it plays
        // almost nowhere else; the CDN renders the same image as a GIF.
        $media = DiscordMedia::fromUrl('https://cdn.discordapp.com/banners/211656972624199681/a_8495e8c8c8a52c8d6a6fbec68282506d.webp?size=1024&animated=true');

        $this->assertNotNull($media);
        $this->assertSame('https://cdn.discordapp.com/banners/211656972624199681/a_8495e8c8c8a52c8d6a6fbec68282506d.gif?size=1024', $media->url);
        $this->assertSame('image/gif', $media->mimeType);
        $this->assertSame('a_8495e8c8c8a52c8d6a6fbec68282506d.gif', $media->name);
        $this->assertTrue($media->isImage());
    }

    public function testAnAnimatedEmojiIsAGifToo(): void
    {
        $media = DiscordMedia::fromUrl('https://cdn.discordapp.com/emojis/123456789012345678.webp?size=96&animated=true');

        $this->assertSame('https://cdn.discordapp.com/emojis/123456789012345678.gif?size=96', $media?->url);
        $this->assertSame('image/gif', $media?->mimeType);
    }

    public function testAStillImageKeepsItsFormat(): void
    {
        $media = DiscordMedia::fromUrl('https://cdn.discordapp.com/avatars/1/abc.webp?size=256');

        $this->assertSame('https://cdn.discordapp.com/avatars/1/abc.webp?size=256', $media?->url);
        $this->assertSame('image/webp', $media?->mimeType);
    }

    public function testAnUploadedFileIsTakenAsItIs(): void
    {
        // Attachments are not rendered on request, and their signature must
        // survive untouched.
        $gif = 'https://cdn.discordapp.com/attachments/1/2/dance.gif?ex=66&is=65&hm=abc';
        $webp = 'https://media.discordapp.net/attachments/1/2/spin.webp?animated=true';

        $this->assertSame($gif, DiscordMedia::fromUrl($gif)?->url);
        $this->assertSame('image/gif', DiscordMedia::fromUrl($gif)?->mimeType);
        $this->assertSame($webp, DiscordMedia::fromUrl($webp)?->url);
        $this->assertSame('image/webp', DiscordMedia::fromUrl($webp)?->mimeType);
    }

    public function testAnythingElseIsNotAPicture(): void
    {
        foreach ([
            'https://tenor.com/view/dance-gif-123',
            'https://example.com/dance.gif',
            'http://cdn.discordapp.com/emojis/1.gif',
            'https://cdn.discordapp.com/attachments/1/2/clip.mp4',
            'https://cdn.discordapp.com.example.com/emojis/1.gif',
            'https://user@cdn.discordapp.com/emojis/1.gif',
            'look https://cdn.discordapp.com/emojis/1.gif',
            '<https://cdn.discordapp.com/emojis/1.gif>',
            '',
        ] as $url) {
            $this->assertNull(DiscordMedia::fromUrl($url), $url);
        }
    }
}
