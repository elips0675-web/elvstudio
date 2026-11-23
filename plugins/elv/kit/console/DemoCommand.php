<?php namespace Elv\Kit\Console;

use Elv\Kit\Classes\Demo;
use Elv\Kit\Classes\Slots;
use Illuminate\Console\Command;

/**
 * DemoCommand — наполняет сайт демо-каталогом, статьями и слотами.
 *
 * Команда идемпотентна: повторный запуск не создаёт дублей, а только
 * досоздаёт недостающее и обновляет сетку слотов. Пресет ниши
 * (`--preset=booking|digital|agency|expert`) меняет только настройки:
 * бренд, услуги, FAQ, меню и услуги записи.
 */
class DemoCommand extends Command
{
    protected $signature = 'elv:kit:demo
        {--preset= : пресет ниши: booking, digital, agency, expert}
        {--reset-preset : вернуть демо-настройки Elv Kit}
        {--refresh-slots : только обновить сетку слотов}
        {--refresh-posts : перезаписать тексты демо-статей}
        {--refresh-catalog : обновить названия и описания разделов}
        {--days=21 : на сколько дней вперёд генерировать слоты}';

    protected $description = 'Наполняет Elv Kit демо-данными (каталог, статьи, слоты, пресеты ниш).';

    public function handle()
    {
        $days = max(1, (int) $this->option('days'));
        $preset = trim((string) ($this->option('preset') ?: env('ELV_DEMO_PRESET', '')));

        if ($preset !== '' && !Demo::presetExists($preset)) {
            $this->error('Неизвестный пресет «' . $preset . '». Доступны: ' . implode(', ', Demo::availablePresets()));

            return self::FAILURE;
        }

        if ($this->option('refresh-slots') && ($preset !== '' || $this->option('reset-preset'))) {
            $this->warn('Режим --refresh-slots обновляет только слоты: настройки пресета не применены.');
        }

        if (!$this->option('refresh-slots')) {
            $created = Demo::seed((bool) $this->option('refresh-posts'), (bool) $this->option('refresh-catalog'));
            Demo::contacts();
            Demo::menu();
            Demo::faq();
            Demo::bookingServices();

            if (!Demo::hasPostsDataset()) {
                $this->warn('Статьи блога не входят в этот архив (plugin/demo/blog.json): блог останется пустым.');
            }

            if ($this->option('refresh-posts')) {
                $this->info('Тексты демо-статей обновлены из demo/blog.json.');
            }

            if ($this->option('refresh-catalog')) {
                $this->info('Названия и описания разделов обновлены из классов\Demo.');
            }

            $this->info(sprintf(
                'Демо-данные: категорий %d, товаров %d, статей %d.',
                $created['categories'],
                $created['products'],
                $created['posts']
            ));

            if ($this->option('reset-preset')) {
                Demo::resetPresetSettings();
                Demo::contacts();
                Demo::menu();
                Demo::faq();
                Demo::bookingServices();
                $this->info('Настройки пресета сброшены к демо-значениям Elv Kit.');
            }

            if ($preset !== '') {
                $applied = Demo::applyPreset($preset);
                $this->info(sprintf('Пресет «%s»: обновлено настроек — %d.', $preset, count($applied)));
            }
        }

        $orphans = ($preset !== '' || $this->option('reset-preset')) ? Slots::pruneOrphans() : 0;
        $slots = Demo::slots($days);
        $pruned = Slots::prune();

        $this->info(sprintf(
            'Слоты: создано %d, удалено прошедших %d%s.',
            $slots,
            $pruned,
            $orphans > 0 ? ', удалено чужих услуг ' . $orphans : ''
        ));

        return self::SUCCESS;
    }
}
