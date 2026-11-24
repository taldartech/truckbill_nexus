<?php

use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyOptionQuery;
use App\Domains\Master\Party\PartyService;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../Support/OwnFleetTripHelpers.php';
require_once __DIR__.'/../Support/RbacPersonaHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('party options endpoint returns role-filtered searchable results', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $parties = app(PartyService::class);
    $owner = $ctx['owner'];
    $company = $ctx['company'];

    $alpha = $parties->create($owner, $company, null, [
        'name' => 'Alpha Customer Searchable',
        'contact_number' => '9810000001',
        'state' => 'MH',
        'city' => 'Pune',
    ], [['role' => PartyRoleType::Customer->value]], 'acs');

    $parties->create($owner, $company, null, [
        'name' => 'Beta Transporter Only',
        'contact_number' => '9810000002',
        'state' => 'MH',
        'city' => 'Nashik',
        'pan' => 'AABCT9999C',
        'is_gst_applicable' => false,
    ], [[
        'role' => PartyRoleType::Transporter->value,
        'attributes' => [
            'pan' => 'AABCT9999C',
            'pan_status' => 'active',
            'declaration' => true,
            'declaration_type' => 'owner',
            'goods_vehicles_count' => 1,
        ],
    ]], 'bto');

    rbacHttpAs($ctx['owner'], $ctx);

    $this->getJson(route('branch.parties.options', [
        'role' => PartyRoleType::Customer->value,
        'q' => 'Alpha Customer',
        'limit' => 20,
    ]))
        ->assertOk()
        ->assertJsonPath('meta.count', 1)
        ->assertJsonPath('data.0.id', $alpha->id)
        ->assertJsonMissing(['name' => 'Beta Transporter Only']);
});

test('party option query search finds parties beyond the initial bounded limit', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $parties = app(PartyService::class);
    $owner = $ctx['owner'];
    $company = $ctx['company'];
    $branch = $ctx['branch'];

    for ($i = 1; $i <= 55; $i++) {
        $parties->create($owner, $company, null, [
            'name' => sprintf('Bulk Customer %03d', $i),
            'contact_number' => sprintf('98200%05d', $i),
            'state' => 'MH',
            'city' => 'Mumbai',
        ], [['role' => PartyRoleType::Customer->value]], sprintf('bc%03d', $i));
    }

    $needle = $parties->create($owner, $company, null, [
        'name' => 'Zebra Unique Search Target',
        'contact_number' => '9899988776',
        'state' => 'MH',
        'city' => 'Solapur',
    ], [['role' => PartyRoleType::Customer->value]], 'zust');

    $initial = app(PartyOptionQuery::class)->forRoles(
        $company,
        $branch,
        PartyRoleType::Customer,
        50,
    );
    $initialIds = collect($initial)->pluck('id')->all();
    expect($initialIds)->not->toContain($needle->id);

    $byName = app(PartyOptionQuery::class)->forRoles(
        $company,
        $branch,
        PartyRoleType::Customer,
        20,
        'Zebra Unique',
    );
    expect(collect($byName)->pluck('id')->all())->toContain($needle->id);

    $byCity = app(PartyOptionQuery::class)->forRoles(
        $company,
        $branch,
        PartyRoleType::Customer,
        20,
        'Solapur',
    );
    expect(collect($byCity)->pluck('id')->all())->toContain($needle->id);

    $byContact = app(PartyOptionQuery::class)->forRoles(
        $company,
        $branch,
        PartyRoleType::Customer,
        20,
        '9899988776',
    );
    expect(collect($byContact)->pluck('id')->all())->toContain($needle->id);
});

test('party options HTTP search returns the matched party', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $parties = app(PartyService::class);
    $owner = $ctx['owner'];
    $company = $ctx['company'];

    $needle = $parties->create($owner, $company, null, [
        'name' => 'Remote Search Customer',
        'contact_number' => '9876543210',
        'state' => 'MH',
        'city' => 'Kolhapur',
    ], [['role' => PartyRoleType::Customer->value]], 'rsc');

    rbacHttpAs($ctx['owner'], $ctx);

    $this->getJson(route('branch.parties.options', [
        'role' => PartyRoleType::Customer->value,
        'q' => 'Kolhapur',
        'limit' => 20,
    ]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $needle->id);
});

test('party options includes selected party even when outside the page window', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $parties = app(PartyService::class);
    $owner = $ctx['owner'];
    $company = $ctx['company'];

    for ($i = 1; $i <= 30; $i++) {
        $parties->create($owner, $company, null, [
            'name' => sprintf('AAA Customer %03d', $i),
            'contact_number' => sprintf('98300%05d', $i),
            'state' => 'MH',
            'city' => 'Pune',
        ], [['role' => PartyRoleType::Customer->value]], sprintf('aaa%03d', $i));
    }

    $selected = $parties->create($owner, $company, null, [
        'name' => 'ZZZ Selected Customer',
        'contact_number' => '9844411223',
        'state' => 'MH',
        'city' => 'Nagpur',
    ], [['role' => PartyRoleType::Customer->value]], 'zzzs');

    rbacHttpAs($ctx['owner'], $ctx);

    $response = $this->getJson(route('branch.parties.options', [
        'role' => PartyRoleType::Customer->value,
        'limit' => 10,
        'selected_id' => $selected->id,
    ]))->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($selected->id);
});

test('party options rejects invalid role', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    rbacHttpAs($ctx['owner'], $ctx);

    $this->getJson(route('branch.parties.options', [
        'role' => 'not-a-role',
    ]))->assertUnprocessable();
});

test('party option query ignores forged cross-company selected party', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $local = app(PartyService::class)->create($ctx['owner'], $ctx['company'], null, [
        'name' => 'Local Customer',
        'contact_number' => '9855500001',
        'state' => 'MH',
        'city' => 'Pune',
    ], [['role' => PartyRoleType::Customer->value]], 'loc');

    $foreign = tripWorkspace();
    tripActivate($foreign['company'], $foreign['branch']);

    $foreignParty = app(PartyService::class)->create(
        $foreign['owner'],
        $foreign['company'],
        null,
        [
            'name' => 'Foreign Company Customer',
            'contact_number' => '9855512345',
            'state' => 'MH',
            'city' => 'Thane',
        ],
        [['role' => PartyRoleType::Customer->value]],
        'fcc',
    );

    tripActivate($ctx['company'], $ctx['branch']);

    $options = app(PartyOptionQuery::class)->forRoles(
        $ctx['company'],
        $ctx['branch'],
        PartyRoleType::Customer,
        10,
        null,
        $foreignParty->id,
    );

    $ids = collect($options)->pluck('id')->all();
    expect($ids)->toContain($local->id)
        ->and($ids)->not->toContain($foreignParty->id);
});
