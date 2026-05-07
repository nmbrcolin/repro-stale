<?php

namespace App\Console\Commands;

use App\Models\Widget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;

class WatchCommand extends Command
{
    protected $signature = 'repro:watch
        {--deadline=5 : Seconds after the last dispatch+queue-drain before flagging stuck stale_since}';

    protected $description = 'Poll the queue + DB; report any widget with stale_since stuck after the queue drains';

    public function handle(): int
    {
        $deadline = (int) $this->option('deadline');

        $this->info('# Watching for stuck stale_since…');

        $idleSince = null;
        $sawActive = false;

        while (true) {
            $queueLen = $this->getQueueDepth();
            $widgetStuck = Widget::query()->whereNotNull('stale_since')->count();

            $this->line(sprintf(
                'queue=%d widgets_stale=%d',
                $queueLen,
                $widgetStuck,
            ));

            if ($queueLen > 0) {
                $sawActive = true;
            }

            $idle = ($queueLen === 0) && $sawActive;
            if ($idle) {
                $idleSince ??= time();
                if (time() - $idleSince >= $deadline) {
                    if ($widgetStuck > 0) {
                        $this->error("# FAILED: Queue was idle {$deadline}s, but {$widgetStuck} widget(s) still stale.");
                        $this->table(
                            ['id', 'stale_since', 'updated_at'],
                            Widget::whereNotNull('stale_since')
                                ->limit(20)
                                ->get()
                                ->map(fn ($w) => [$w->id, (string) $w->stale_since, (string) $w->updated_at])
                            ->all()
                        );

                        return self::FAILURE;
                    }

                    $this->info("# PASSED: Queue was idle for {$deadline}s with no stuck stale_since.");

                    return self::SUCCESS;
                }
            } else {
                $idleSince = null;
            }

            Sleep::for(1)->seconds();
        }
    }

    private function getQueueDepth(): int
    {
        $conn = Redis::connection();
        $queue = config('queue.connections.redis.queue', 'default');

        $waiting = (int) $conn->llen("queues:{$queue}");
        $delayed = (int) $conn->zcard("queues:{$queue}:delayed");
        $reserved = (int) $conn->zcard("queues:{$queue}:reserved");

        return $waiting + $delayed + $reserved;
    }
}
