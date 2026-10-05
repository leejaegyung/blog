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

        // 네이버 지도 앱 "공유 → 복사" 글 통째로
        $shared = MapUrl::parse("[네이버 지도]\n파스타인계 본점\n경기 수원시 팔달구 인계로 123\nhttps://naver.me/5abcDEF");
        $this->assertSame(['naver', '파스타인계 본점', '경기 수원시 팔달구 인계로 123', 'https://naver.me/5abcDEF', true],
            [$shared['source'], $shared['name'], $shared['address'], $shared['url'], $shared['short']]);
        $inline = MapUrl::parse('[네이버 지도] 파스타인계 https://naver.me/5abcDEF');
        $this->assertSame('파스타인계', $inline['name']);

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

    public function test_chosen_places_are_saved_with_the_post_and_can_be_cleared(): void
    {
        $post = Post::factory()->create();
        $first = ['name' => '카시오 스토어 도산', 'road_address' => '서울 강남구 압구정로46길 27', 'lat' => 37.52, 'lng' => 127.03, 'map_url' => 'https://naver.me/abc', 'source' => 'naver', 'extra' => 'x'];
        $second = ['name' => '서울숲', 'road_address' => '서울 성동구 뚝섬로 273', 'kakao_url' => 'http://place.map.kakao.com/2'];

        $this->actingAs($post->user)->patchJson("/api/posts/{$post->id}", ['places' => [$first, $second]])
            ->assertOk()->assertJsonPath('data.places.0.name', '카시오 스토어 도산')->assertJsonPath('data.places.1.name', '서울숲')
            ->assertJsonMissingPath('data.places.0.extra');
        $this->actingAs($post->user)->patchJson("/api/posts/{$post->id}", ['places' => [['name' => 'x', 'map_url' => 'javascript:alert(1)']]])
            ->assertJsonValidationErrors('places.0.map_url');
        $this->actingAs($post->user)->patchJson("/api/posts/{$post->id}", ['places' => []])->assertJsonPath('data.places', []);

        // 예전에 장소 하나(객체)로 저장한 글도 목록으로 보인다
        $post->forceFill(['place_json' => $first])->save();
        $this->actingAs($post->user)->getJson("/api/posts/{$post->id}")->assertJsonPath('data.places.0.name', '카시오 스토어 도산');
    }

    public function test_detect_finds_places_mentioned_in_the_facts(): void
    {
        $post = Post::factory()->create();
        AppSetting::create(['key' => BlogSettingsController::KAKAO_KEY, 'value' => encrypt('k', false)]);
        Http::fake([
            '*/places/candidates' => Http::response(['candidates' => ['카시오 도산점', '서울숲 산책', '돋자리']]),
            KakaoLocal::KEYWORD.'*' => fn ($r) => Http::response(['documents' => match ($r['query']) {
                '카시오 도산점' => [['id' => '1', 'place_name' => '카시오 스토어 도산', 'road_address_name' => '서울 강남구 압구정로46길 27', 'x' => '127.03', 'y' => '37.52']],
                '서울숲 산책' => [['id' => '2', 'place_name' => '서울숲', 'road_address_name' => '서울 성동구 뚝섬로 273', 'x' => '127.04', 'y' => '37.54']],
                default => [['id' => '9', 'place_name' => '캠핑용품 할인점', 'x' => '127', 'y' => '37']],
            }]),
        ]);

        $data = $this->actingAs($post->user)->postJson('/api/places/detect', ['texts' => ['카시오 도산점 방문 후 서울숲에서 돋자리 펴고 F1'], 'near' => ['lat' => 37.52, 'lng' => 127.03], 'exclude' => ['1']])
            ->assertOk()->json('data');

        // 이미 연결한 장소(1)는 빼고, 이름이 겹치지 않는 결과(돋자리 → 캠핑용품 할인점)는 버린다
        $this->assertSame([['서울숲 산책', '서울숲']], array_map(fn ($p) => [$p['query'], $p['name']], $data));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'keyword.json') && $r['radius'] == 20000);
    }
}
