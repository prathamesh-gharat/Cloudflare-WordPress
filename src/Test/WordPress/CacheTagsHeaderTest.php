<?php

namespace Cloudflare\APO\Test\WordPress;

use Cloudflare\APO\WordPress\CacheTagsHeader;
use phpmock\phpunit\PHPMock;

class CacheTagsHeaderTest extends \PHPUnit\Framework\TestCase
{
    use PHPMock;

    public function testInitRegistersHook()
    {
        $mockAddAction = $this->getFunctionMock('Cloudflare\APO\WordPress', 'add_action');
        $mockAddAction->expects($this->once())
            ->with('send_headers', [CacheTagsHeader::class, 'send_cache_tag_headers'], 20);

        CacheTagsHeader::init();
    }

    public function testSendCacheTagHeadersDoesNothingIfShouldNotTag()
    {
        $mockApplyFilters = $this->getFunctionMock('Cloudflare\APO\WordPress', 'apply_filters');
        $mockApplyFilters->expects($this->any())->willReturnCallback(function($hook, $value) {
            if ($hook === 'cf_cache_tags_enabled') {
                return false;
            }
            return $value;
        });

        $mockHeader = $this->getFunctionMock('Cloudflare\APO\WordPress', 'header');
        $mockHeader->expects($this->never());

        CacheTagsHeader::send_cache_tag_headers();
    }

    public function testSendCacheTagHeadersSendsHomeTagOnFrontPage()
    {
        // Mock should_tag_response to return true
        $mocks = $this->setupShouldTagMocks(true);
        $mockApplyFilters = $mocks['apply_filters'];

        // Mock build_tags dependencies
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_front_page')->expects($this->any())->willReturn(true);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_home')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_category')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_tag')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_tax')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_singular')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_post_type_archive')->expects($this->any())->willReturn(false);

        $mockHeader = $this->getFunctionMock('Cloudflare\APO\WordPress', 'header');
        $mockHeader->expects($this->once())
            ->with('Cache-Tag:home', false);

        CacheTagsHeader::send_cache_tag_headers();
    }

    public function testSanitizeTag()
    {
        $reflection = new \ReflectionClass(CacheTagsHeader::class);
        $method = $reflection->getMethod('sanitize_tag');
        $method->setAccessible(true);

        $this->assertEquals('tag', $method->invoke(null, 'TAG'));
        $this->assertEquals('tag', $method->invoke(null, 'tag '));
        $this->assertEquals('tagname', $method->invoke(null, 'tag name'));
        $this->assertEquals('tag_name', $method->invoke(null, 'tag,name'));
        $this->assertEquals('tag_name', $method->invoke(null, 'tag___name'));
        $this->assertEquals('!!!tag!!!', $method->invoke(null, '!!!tag!!!'));
        $this->assertEquals('tag', $method->invoke(null, ''));
    }

    public function testSanitizeTagTruncatesLongTags()
    {
        $reflection = new \ReflectionClass(CacheTagsHeader::class);
        $method = $reflection->getMethod('sanitize_tag');
        $method->setAccessible(true);

        $longTag = str_repeat('a', 2000);
        $sanitized = $method->invoke(null, $longTag);

        $this->assertEquals(1024, strlen($sanitized));
        $this->assertEquals(str_repeat('a', 1024), $sanitized);
    }

    public function testSendCacheTagHeadersChunksLargeTags()
    {
        // Combine all filter logic into one callback to avoid phpmock conflicts
        $largeTags = [];
        for ($i = 0; $i < 100; $i++) {
            $largeTags[] = str_repeat('a', 100) . $i;
        }

        $mockApplyFilters = $this->getFunctionMock('Cloudflare\APO\WordPress', 'apply_filters');
        $mockApplyFilters->expects($this->any())->willReturnCallback(function($hook, $value) use ($largeTags) {
            if ($hook === 'cf_cache_tags_enabled') {
                return true;
            }
            if ($hook === 'cf_cache_tags') {
                return $largeTags;
            }
            return $value;
        });

        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_admin')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'wp_doing_ajax')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_feed')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_trackback')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_robots')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_favicon')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'headers_sent')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_front_page')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_home')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_category')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_tag')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_tax')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_singular')->expects($this->any())->willReturn(false);
        $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_post_type_archive')->expects($this->any())->willReturn(false);

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $mockHeader = $this->getFunctionMock('Cloudflare\APO\WordPress', 'header');
        $mockHeader->expects($this->exactly(2));

        CacheTagsHeader::send_cache_tag_headers();
    }

    private function setupShouldTagMocks($enabled = true)
    {
        $mockApplyFilters = $this->getFunctionMock('Cloudflare\APO\WordPress', 'apply_filters');
        $mockApplyFilters->expects($this->any())->willReturnCallback(function($hook, $value) use ($enabled) {
            if ($hook === 'cf_cache_tags_enabled') {
                return $enabled;
            }
            return $value;
        });

        $mockIsAdmin = $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_admin');
        $mockIsAdmin->expects($this->any())->willReturn(false);

        $mockWpDoingAjax = $this->getFunctionMock('Cloudflare\APO\WordPress', 'wp_doing_ajax');
        $mockWpDoingAjax->expects($this->any())->willReturn(false);

        $mockIsFeed = $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_feed');
        $mockIsFeed->expects($this->any())->willReturn(false);

        $mockIsTrackback = $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_trackback');
        $mockIsTrackback->expects($this->any())->willReturn(false);

        $mockIsRobots = $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_robots');
        $mockIsRobots->expects($this->any())->willReturn(false);

        $mockIsFavicon = $this->getFunctionMock('Cloudflare\APO\WordPress', 'is_favicon');
        $mockIsFavicon->expects($this->any())->willReturn(false);

        $mockHeadersSent = $this->getFunctionMock('Cloudflare\APO\WordPress', 'headers_sent');
        $mockHeadersSent->expects($this->any())->willReturn(false);

        $_SERVER['REQUEST_METHOD'] = 'GET';

        return [
            'apply_filters' => $mockApplyFilters,
            'is_admin' => $mockIsAdmin,
        ];
    }
}
