<?php

namespace App\Console\Commands;

use App\Models\Widget;
use Illuminate\Console\Command;

class SeedCommand extends Command
{
    protected $signature = 'repro:seed {--widgets=10 : Number of widgets to create}';

    protected $description = 'Wipe widgets and create N fresh ones';

    public function handle(): int
    {
        // Delete any existing widgets.
        Widget::query()->delete();

        // Create n new widgets.
        $count = (int) $this->option('widgets');
        collect(range(1, $count))->each(fn() => Widget::create());

        $this->info("Created {$count} widget(s).");

        return self::SUCCESS;
    }
}
