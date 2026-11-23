<?php namespace Elv\Kit\Models;

use Model;

/**
 * Release — опубликованная сборка архива Elv Kit.
 *
 * Запись создаёт `elv:kit:package`, когда архив собран. Действующая сборка
 * (`is_current`) отдаётся покупателю; если файл пропал, `DownloadService`
 * собирает архив заново и пишет предупреждение в лог.
 */
class Release extends Model
{
    use \October\Rain\Database\Traits\Validation;

    public $table = 'elv_kit_releases';

    public $fillable = [
        'product_slug',
        'version',
        'channel',
        'file',
        'size',
        'checksum',
        'notes',
        'is_demo',
        'is_current',
        'published_at',
    ];

    public $rules = [
        'version' => 'required|max:32',
        'file' => 'required|max:255',
        'channel' => 'required|in:stable,beta',
    ];

    protected $casts = [
        'is_demo' => 'boolean',
        'is_current' => 'boolean',
        'published_at' => 'datetime',
    ];

    /**
     * beforeSave — действующая сборка у канала одна.
     */
    public function beforeSave()
    {
        if (!$this->is_current) {
            return;
        }

        static::query()
            ->where('product_slug', (string) ($this->product_slug ?: 'elv-kit'))
            ->where('is_demo', (bool) $this->is_demo)
            ->where('id', '!=', (int) $this->id)
            ->update(['is_current' => false]);
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function getAbsolutePathAttribute(): string
    {
        return storage_path('app/' . ltrim(str_replace('\\', '/', (string) $this->file), '/'));
    }

    public function getFileExistsAttribute(): bool
    {
        return is_file($this->absolute_path) && filesize($this->absolute_path) > 0;
    }

    public function getChannelLabelAttribute(): string
    {
        return $this->channel === 'beta' ? 'Бета' : 'Стабильный';
    }

    public function getSizeLabelAttribute(): string
    {
        $bytes = (int) $this->size;

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1, ',', ' ') . ' МБ';
        }

        return number_format($bytes / 1024, 1, ',', ' ') . ' КБ';
    }

    public function getPublishedLabelAttribute(): string
    {
        return $this->published_at ? $this->published_at->format('d.m.Y H:i') : '—';
    }
}
