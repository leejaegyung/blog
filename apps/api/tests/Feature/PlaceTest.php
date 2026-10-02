<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BlogSettingsController;
use App\Models\AppSetting;
use App\Models\Post;
use App\Services\KakaoLocal;
use App\Support\MapUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 3단계 "장소 연결": 지도 링크 글자에서 이름·좌표를 읽고(링크는 열지 않음) 카카오 로컬 API로 정확한 장소를 찾는다. */
class PlaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_links_are_read_without_opening_them(): void
    {
        $google = MapUrl::parse('https://www.google.com/maps/place/%ED%8C%8C%EC%8A%A4%ED%83%80+%EC%9D%B8%EA%B3%84/@37.2636,127.0306,17z/data=!3m1');
        $this->assertSame(['google', '파스타 인계', 37.2636, 127.0306], [$google['source'], $google['name'], $google['lat'], $google['lng']]);

        $naver = MapUrl::parse('https://map.naver.com/p/search/%EC%9D%B8%EA%B3%84%EB%8F%99%20%ED%8C%8C%EC%8A%A4%ED%83%80/place/1234567?c=15.00,0,0,0,dh');
        $this->assertSame(['naver', '인계동 파스타', null], [$naver['source'], $naver['name'], $naver['lat']]);

        // 예전 네이버 지도 좌표(웹 메르카토르)
        $old = MapUrl::parse('https://map.naver.com/v5/entry/place/123?c=14141234.5,4473500.1,15,0,0,0,dh');
        $this->assertEqualsWithDelta(127.03, $old['lng'], 0.01);
        $this->assertEqualsWithDelta(37.26, $old['lat'], 0.05);

        // 단축 링크는 열지 않으니 함께 적은 이름을 쓴다
        $short = MapUrl::parse('https://naver.me/xYz12 파스타인계 ');
        $this->assertSame([true, '파스타인계', 'naver'], [$short['short'], $short['name'], $short['source']]);
        $this->assertNull(MapUrl::parse('https://naver.me/xYz12')['name']);

        $this->assertSame(['text', '수원 파스타인계'], array_values(array_intersect_key(MapUrl::parse('수원  파스타인계'), ['source' => 0, 'name' => 0])));
    }

    public function test_lookup_finds_places_near_the_link_with_kakao_local(): void
    {
        $post = Post::factory()->create();
        AppSetting::create(['key' => BlogSettingsController::KAKAO_KEY, 'value' => encrypt('k', false)]);
        Http::fake([KakaoLocal::KEYWORD.'*' => Http::response(['documents' => [[
            'id' => '111', 'place_name' => '파스타인계', 'category_name' => '음식점 > 양식 > 이탈리안', 'phone' => '031-000-0000',
            'address_name' => '경기 수원시 팔달구 인계동 1', 'road_address_name' => '경기 수원시 팔달구 인계로 1', 'x' => '127.03', 'y' => '37.26',
            'place_url' => 'http://place.map.kakao.com/111', 'distance' => '120',
        ]]])]);

        $this->actingAs($post->user)->postJson('/api/places/lookup', ['input' => 'https://www.google.com/maps/place/파스타인계/@37.2636,127.0306,17z'])
            ->assertOk()->assertJsonPath('data.0.name', '파스타인계')->assertJsonPath('data.0.road_address', '경기 수원시 팔달구 인계로 1')
            ->assertJsonPath('data.0.distance_m', 120)->assertJsonPath('parsed.source', 'google');
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'KakaoAK k') && $r['query'] === '파스타인계' && $r['x'] == 127.0306 && $r['radius'] == 3000);

        // 네이버 도메인은 열지 않는다
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'naver'));
        $this->actingAs($post->user)->postJson('/api/places/lookup', ['input' => 'https://naver.me/abc'])->assertJsonValidationErrors('input');
    }

    public function test_lookup_explains_how_to_turn_on_kakao_map(): void
    {
        $post = Post::factory()->create();
        AppSetting::create(['key' => BlogSettingsController::KAKAO_KEY, 'value' => encrypt('k', false)]);
        Http::fake([KakaoLocal::KEYWORD.'*' => Http::response(['errorType' => 'NotAuthorizedError', 'message' => 'App(블로그) disabled OPEN_MAP_AND_LOCAL service.'], 403)]);

        $message = $this->actingAs($post->user)->postJson('/api/places/lookup', ['input' => '파스타인계'])->assertJsonValidationErrors('input')->json('errors.input.0');
        $this->assertStringContainsString('카카오맵', $message);
    }

    public function test_chosen_place_is_saved_with_the_post_and_can_be_cleared(): void
    {
        $post = Post::factory()->create();
        $place = ['name' => '파스타인계', 'road_address' => '경기 수원시 팔달구 인계로 1', 'lat' => 37.26, 'lng' => 127.03, 'map_url' => 'https://naver.me/abc', 'source' => 'naver', 'extra' => 'x'];

        $this->actingAs($post->user)->patchJson("/api/posts/{$post->id}", ['place' => $place])
            ->assertOk()->assertJsonPath('data.place.name', '파스타인계')->assertJsonPath('data.place.map_url', 'https://naver.me/abc')
            ->assertJsonMissingPath('data.place.extra');
        $this->actingAs($post->user)->patchJson("/api/posts/{$post->id}", ['place' => ['name' => 'x', 'map_url' => 'javascript:alert(1)']])
            ->assertJsonValidationErrors('place.map_url');
        $this->actingAs($post->user)->patchJson("/api/posts/{$post->id}", ['place' => null])->assertJsonPath('data.place', null);
    }
}
