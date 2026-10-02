<?php

namespace Vibefilter\Filament\Tests;

use Illuminate\Support\Arr;
use Livewire\Livewire;
use Vibefilter\Filament\Contracts\DecisionDriver;
use Vibefilter\Filament\Drivers\FakeDriver;
use Vibefilter\Filament\Support\Numbers;
use Vibefilter\Filament\Tests\Fixtures\ListReviews;
use Vibefilter\Filament\Tests\Fixtures\Review;

class TranslationTest extends TestCase
{
    public function test_every_language_has_every_key_of_the_english_file(): void
    {
        $english = array_keys(Arr::dot(require __DIR__ . '/../resources/lang/en/vibefilter.php'));

        foreach (glob(__DIR__ . '/../resources/lang/*/vibefilter.php') as $file) {
            $keys = array_keys(Arr::dot(require $file));

            $this->assertSame([], array_values(array_diff($english, $keys)), 'Missing in ' . $file);
            $this->assertSame([], array_values(array_diff($keys, $english)), 'Unknown keys in ' . $file);
        }
    }

    public function test_numbers_use_the_languages_separators(): void
    {
        $this->assertSame('1,234.5', Numbers::format(1234.5, 1));

        app()->setLocale('hu');

        $this->assertSame("1\u{00A0}234,5", Numbers::format(1234.5, 1));

        app()->setLocale('es');

        $this->assertSame('1.234,5', Numbers::format(1234.5, 1));
    }

    public function test_money_keeps_two_significant_digits_below_a_cent(): void
    {
        $this->assertSame('0.0031', Numbers::money(0.00312));
        $this->assertSame('0.000013', Numbers::money(0.0000133));
        $this->assertSame('0.27', Numbers::money(0.271));
        $this->assertSame('0.00', Numbers::money(0.0));
    }

    public function test_the_filter_speaks_the_apps_language(): void
    {
        app()->setLocale('hu');
        $this->app->instance(DecisionDriver::class, new FakeDriver(fn (string $statement, string $text) => str_contains($text, 'furious') ? 0.95 : 0.05));
        Review::create(['body' => 'I am furious.']);
        Review::create(['body' => 'All good.']);

        Livewire::test(ListReviews::class)
            ->filterTable('vibe', ['statement' => 'The customer is angry.'])
            ->assertNotified('2 sorból 1 illik a vibe-hoz');
    }

    public function test_the_limit_pop_up_and_the_progress_bar_are_translated(): void
    {
        app()->setLocale('hu');
        config(['vibefilter.max_unscored_rows' => 1]);
        $this->app->instance(DecisionDriver::class, new FakeDriver);
        Review::create(['body' => 'One.']);
        Review::create(['body' => 'Two.']);

        Livewire::test(ListReviews::class)
            ->filterTable('vibe', ['statement' => 'The customer is angry.'])
            ->assertNotified('2 sor vár pontozásra');

        $bar = view('vibefilter::progress', ['rows' => 2, 'done' => 0, 'total' => 1, 'retries' => 0, 'cost' => 0.0031])->render();

        $this->assertStringContainsString('2 sor pontozása', $bar);
        $this->assertStringContainsString('0 / 1 kérés kész', $bar);
        $this->assertStringContainsString("Költség:\u{00A0}\$0,0031", $bar);
    }

    public function test_spanish_singular_and_plural(): void
    {
        app()->setLocale('es');
        config(['vibefilter.max_unscored_rows' => 1]);
        $this->app->instance(DecisionDriver::class, new FakeDriver);
        Review::create(['body' => 'One.']);
        Review::create(['body' => 'Two.']);

        Livewire::test(ListReviews::class)
            ->filterTable('vibe', ['statement' => 'The customer is angry.'])
            ->assertNotified('2 filas necesitan una puntuación nueva');

        $bar = view('vibefilter::progress', ['rows' => 1, 'done' => 0, 'total' => 1, 'retries' => 0, 'cost' => 0.0031])->render();

        $this->assertStringContainsString('Puntuando 1 fila', $bar);
        $this->assertStringContainsString('0 / 1 solicitud completada', $bar);
        $this->assertStringContainsString("Costo:\u{00A0}\$0,0031", $bar);
    }

    public function test_a_single_retry_is_singular(): void
    {
        app()->setLocale('es');

        $bar = view('vibefilter::progress', ['rows' => 1, 'done' => 0, 'total' => 1, 'retries' => 1, 'cost' => null])->render();

        $this->assertStringContainsString('1 reintentada', $bar);
        $this->assertStringNotContainsString('1 reintentadas', $bar);
    }
}
