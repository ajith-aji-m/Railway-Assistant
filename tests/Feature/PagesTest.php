<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_station_selection_without_location(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Stations/Index')
            ->where('nearby', null)
            ->missing('popular')
            ->reloadOnly('popular', fn (Assert $reload) => $reload->has('popular', 3)));
    }

    public function test_station_selection_with_location_and_search(): void
    {
        $this->get('/?lat=13.0604&lng=80.2496')->assertInertia(fn (Assert $page) => $page
            ->component('Stations/Index')
            ->has('nearby', 5)
            ->where('nearby.0.code', 'MS'));

        $this->get('/?q=chennai')->assertInertia(fn (Assert $page) => $page->has('searchResults', 6)); // name or city
    }

    public function test_invalid_coordinates_are_rejected(): void
    {
        $this->get('/?lat=123&lng=80')->assertSessionHasErrors('lat');
    }

    public function test_station_dashboard(): void
    {
        $this->get('/stations/mas?tab=departures')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Stations/Show')
            ->where('station.code', 'MAS')
            ->where('tab', 'departures')
            ->has('board'));
    }

    public function test_train_pages(): void
    {
        $this->get('/trains?q=kovai')->assertInertia(fn (Assert $page) => $page->component('Trains/Search')->has('results', 2));
        $this->get('/trains')->assertInertia(fn (Assert $page) => $page->where('results', null));
        $this->get('/trains/12675')->assertInertia(fn (Assert $page) => $page->component('Trains/Show')->where('train.number', '12675')->has('train.live.stops', 8));
        $this->get('/trains/12675/map')->assertInertia(fn (Assert $page) => $page->component('Trains/LiveMap')->has('live')->has('train.route', 8));
    }

    public function test_static_pages(): void
    {
        $this->get('/map')->assertOk()->assertInertia(fn (Assert $page) => $page->component('LiveMap/Index'));
        $this->get('/settings')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Settings'));
    }

    public function test_unknown_station_or_train_renders_not_found_page(): void
    {
        $this->get('/stations/XYZ')->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
        $this->get('/trains/00000')->assertNotFound();
    }
}
