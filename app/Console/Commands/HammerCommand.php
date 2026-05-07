<?php

namespace App\Console\Commands;

use App\Jobs\WidgetJob;
use App\Models\Widget;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

class HammerCommand extends Command
{
    protected $signature = 'repro:hammer
        {--duration=60 : Wall-clock seconds to keep dispatching.}
        {--sleep-min=10 : Minimum milliseconds to sleep between dispatches.}
        {--sleep-max=2000 : Maximum milliseconds to sleep between dispatches. Each iteration sleeps a uniform-random duration in [sleep-min, sleep-max] ms.}';

    protected $description = 'Hammer dispatches against the seeded widgets';

    public function handle(): int
    {
        $duration = (int) $this->option('duration');
        $sleepMinMs = (int) $this->option('sleep-min');
        $sleepMaxMs = (int) $this->option('sleep-max');
        if ($sleepMaxMs < $sleepMinMs) {
            $sleepMaxMs = $sleepMinMs;
        }

        $widgets = Widget::all();

        $sleepDesc = $sleepMinMs === $sleepMaxMs ? "{$sleepMinMs}ms" : "[{$sleepMinMs}, {$sleepMaxMs}]ms";
        $this->info("Hammering for {$duration}s across {$widgets->count()} widget(s), sleep={$sleepDesc}...");

        $deadline = microtime(true) + $duration;
        $count = 0;

        while (microtime(true) < $deadline) {
            // Pick a widget at random.
            $widget = $widgets->random();

            // Stale the widget. This might overwrite stale_since on an already-stale widget, but
            // that's okay. We expect the final widget job that gets run to un-stale the widget.
            $widget->update(['stale_since' => now()]);

            // Dispatch the widget job.
            dispatch(new WidgetJob($widget));

            $count++;

            $sleepMs = random_int($sleepMinMs, $sleepMaxMs);
            if ($sleepMs > 0) {
                Sleep::for($sleepMs)->milliseconds();
            }
        }

        $this->info("Hammer complete. {$count} dispatches in {$duration}s.");

        return self::SUCCESS;
    }
}
