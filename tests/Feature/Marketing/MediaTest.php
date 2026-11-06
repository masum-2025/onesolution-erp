<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Marketing\Models\Media;
use App\Platform\Marketing\Models\Page;
use App\Platform\Marketing\Models\Site;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/*
 * MKT-3a: a company website's pictures — uploaded in the partner console,
 * redrawn as WebP, picked in sections and settings, and shown by the site
 * only where its snapshot uses them. Partners never reach each other's.
 */

beforeEach(function () {
    Storage::fake('local');
    config(['marketing.hosts' => 'marketing.test', 'marketing.url' => '']);
    $this->w = tenancyWorld();
    $this->house = Partner::query()->where('is_house', true)->first() ?? Partner::factory()->house()->create(['name' => 'One Solutions']);
    $this->owner = partnerToken(createPartnerStaff($this->house, PartnerUserRole::Owner), $this->house);
    $this->asToken($this->owner)->postJson('/api/partner/website')->assertCreated();
    $this->site = Site::query()->where('partner_id', $this->house->id)->firstOrFail();
    $this->home = Page::query()->where('site_id', $this->site->id)->where('is_home', true)->firstOrFail();
});

function uploadPicture(object $test, string $token, ?UploadedFile $file = null)
{
    return $test->asToken($token)->post('/api/partner/website/media', ['file' => $file ?? UploadedFile::fake()->image('Team photo.jpg', 2400, 1200)], ['Accept' => 'application/json']);
}

it('uploads a picture as two WebP copies, lists it and serves it to the console', function () {
    $media = uploadPicture($this, $this->owner)->assertCreated()->json('data');

    expect($media)->toMatchArray(['name' => 'Team photo', 'width' => 1600, 'height' => 800, 'in_use' => false]);
    $row = Media::query()->findOrFail($media['id']);
    expect($row->mime)->toBe('image/webp')
        ->and(Storage::disk('local')->exists($row->paths['large']))->toBeTrue()
        ->and(Storage::disk('local')->exists($row->paths['small']))->toBeTrue()
        ->and(str_starts_with($row->paths['large'], "marketing/{$this->site->id}/"))->toBeTrue()
        ->and(AuditLog::where('action', 'marketing.media_added')->exists())->toBeTrue();

    $list = $this->asToken($this->owner)->getJson('/api/partner/website/media')->assertOk();
    expect($list->json('data.0.id'))->toBe($media['id'])->and($list->json('meta'))->toMatchArray(['max_mb' => 5, 'max_count' => 300, 'count' => 1, 'can_write' => true]);
    $file = $this->asToken($this->owner)->get("/api/partner/website/media/{$media['id']}/small")->assertOk();
    expect($file->headers->get('Content-Type'))->toBe('image/webp');

    $this->asToken($this->owner)->patchJson("/api/partner/website/media/{$media['id']}", ['alt' => ['en' => 'Our team', 'bn' => 'আমাদের দল']])->assertOk()->assertJsonPath('data.alt.bn', 'আমাদের দল');
    $this->asToken($this->owner)->patchJson("/api/partner/website/media/{$media['id']}", ['site_id' => 'x'])->assertJsonValidationErrors('site_id');
});

it('refuses what is not a picture, what is too big, and more than the rule allows', function () {
    uploadPicture($this, $this->owner, UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo 1;'))->assertUnprocessable()->assertJsonValidationErrors('file');
    uploadPicture($this, $this->owner, UploadedFile::fake()->create('huge.jpg', 6 * 1024, 'image/jpeg'))->assertUnprocessable()
        ->assertJsonPath('errors.file.0', __('marketing.errors.media_too_big', ['mb' => 5]));
    uploadPicture($this, $this->owner, UploadedFile::fake()->create('huge.jpg', 21 * 1024, 'image/jpeg'))->assertUnprocessable();

    // The platform's rule, set for this partner, decides how many it keeps.
    partnerRule($this->house, 'marketing.max_media_count', 1);
    uploadPicture($this, $this->owner)->assertCreated();
    uploadPicture($this, $this->owner)->assertUnprocessable()->assertJsonPath('code', 'media_full');
    expect(Media::query()->count())->toBe(1);
});

it('lets only owners and sales staff change pictures', function () {
    $media = uploadPicture($this, $this->owner)->assertCreated()->json('data.id');
    $sales = partnerToken(createPartnerStaff($this->house, PartnerUserRole::Sales), $this->house);
    $support = partnerToken(createPartnerStaff($this->house, PartnerUserRole::Support), $this->house);

    $this->asToken($support)->getJson('/api/partner/website/media')->assertOk()->assertJsonPath('meta.can_write', false);
    uploadPicture($this, $support)->assertForbidden();
    $this->asToken($support)->deleteJson("/api/partner/website/media/{$media}")->assertForbidden();
    uploadPicture($this, $sales)->assertCreated();
});

it('shows a picture on the site only once a published section uses it, and keeps a used one', function () {
    $media = uploadPicture($this, $this->owner)->assertCreated()->json('data.id');
    $this->asToken($this->owner)->patchJson("/api/partner/website/media/{$media}", ['alt' => ['en' => 'Our office']])->assertOk();
    $this->asToken($this->owner)->postJson('/api/partner/website/publish')->assertOk();

    // Uploaded but not used: not on the site.
    $this->get("http://marketing.test/assets/media/{$media}/large.webp")->assertNotFound();
    URL::forceRootUrl(config('app.url'));

    $this->asToken($this->owner)->postJson("/api/partner/website/pages/{$this->home->id}/blocks", [
        'type' => 'gallery', 'data' => ['items' => [['caption' => ['en' => 'Office'], 'image' => $media, 'color' => 'blue']]],
    ])->assertCreated();
    expect($this->asToken($this->owner)->getJson('/api/partner/website/media')->json('data.0.in_use'))->toBeTrue();
    $this->asToken($this->owner)->deleteJson("/api/partner/website/media/{$media}")->assertStatus(409)->assertJsonPath('code', 'media_in_use');

    // In the drafts: the preview shows it, the site not yet.
    $url = $this->asToken($this->owner)->postJson('/api/partner/website/preview-link')->assertOk()->json('data.url');
    $this->get($url)->assertOk()->assertSee("/website-preview/{$this->site->id}/assets/media/{$media}/large.webp", false)->assertSee('alt="Our office"', false);
    $this->get("/website-preview/{$this->site->id}/assets/media/{$media}/small.webp")->assertOk();
    $this->get("http://marketing.test/assets/media/{$media}/large.webp")->assertNotFound();
    URL::forceRootUrl(config('app.url'));

    $this->asToken($this->owner)->postJson('/api/partner/website/publish')->assertOk();
    $this->get('http://marketing.test/')->assertOk()->assertSee("/assets/media/{$media}/large.webp", false)->assertSee('width="1600"', false);
    $image = $this->get("http://marketing.test/assets/media/{$media}/large.webp")->assertOk();
    expect($image->headers->get('Content-Type'))->toBe('image/webp')->and($image->headers->get('Cache-Control'))->toContain('immutable');
});

it('uses a picture as the sharing picture of the whole site', function () {
    $media = uploadPicture($this, $this->owner)->assertCreated()->json('data.id');
    $this->asToken($this->owner)->patchJson('/api/partner/website', ['base_version' => 1, 'settings' => [...$this->site->settings, 'seo' => ['image' => $media]]])->assertOk();
    $this->asToken($this->owner)->postJson('/api/partner/website/publish')->assertOk();

    $this->get('http://marketing.test/')->assertOk()->assertSee("http://marketing.test/assets/media/{$media}/large.webp", false);
    $this->get("http://marketing.test/assets/media/{$media}/small.webp")->assertOk();
});

it('keeps partners\' pictures apart', function () {
    $houseMedia = uploadPicture($this, $this->owner)->assertCreated()->json('data.id');

    $tokenA = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
    $this->asToken($tokenA)->postJson('/api/partner/website')->assertCreated();
    $siteA = Site::query()->where('partner_id', $this->w->partnerA->id)->firstOrFail();
    $homeA = Page::query()->where('site_id', $siteA->id)->where('is_home', true)->value('id');

    $this->asToken($tokenA)->getJson('/api/partner/website/media')->assertOk()->assertJsonCount(0, 'data');
    $this->asToken($tokenA)->get("/api/partner/website/media/{$houseMedia}/large")->assertNotFound();
    $this->asToken($tokenA)->patchJson("/api/partner/website/media/{$houseMedia}", ['name' => 'Mine'])->assertNotFound();
    $this->asToken($tokenA)->deleteJson("/api/partner/website/media/{$houseMedia}")->assertNotFound();
    // Naming another partner's picture in a section or the settings is refused.
    $this->asToken($tokenA)->postJson("/api/partner/website/pages/{$homeA}/blocks", ['type' => 'text', 'data' => ['body' => ['en' => 'x'], 'image' => $houseMedia]])
        ->assertNotFound()->assertJsonPath('code', 'media_not_found');
    $this->asToken($tokenA)->patchJson('/api/partner/website', ['base_version' => 1, 'settings' => [...$siteA->settings, 'seo' => ['image' => $houseMedia]]])
        ->assertNotFound()->assertJsonPath('code', 'media_not_found');
    expect(Media::query()->whereKey($houseMedia)->value('name'))->toBe('Team photo');

    // Nor can a preview link of one site show another site's picture.
    $url = $this->asToken($tokenA)->postJson('/api/partner/website/preview-link')->assertOk()->json('data.url');
    $this->get("/website-preview/{$siteA->id}/assets/media/{$houseMedia}/large.webp")->assertNotFound();
    expect($url)->toContain($siteA->id);
});

it('removes an unused picture with its files', function () {
    $media = uploadPicture($this, $this->owner)->assertCreated()->json('data.id');
    $paths = Media::query()->findOrFail($media)->paths;

    $this->asToken($this->owner)->deleteJson("/api/partner/website/media/{$media}")->assertOk();
    expect(Media::query()->whereKey($media)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($paths['large']))->toBeFalse()
        ->and(AuditLog::where('action', 'marketing.media_removed')->exists())->toBeTrue();
});
