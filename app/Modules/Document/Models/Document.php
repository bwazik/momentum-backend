<?php

namespace App\Modules\Document\Models;

use App\Models\TenantModel;
use App\Models\User;
use App\Modules\Document\Enums\DocumentEntityType;
use App\Modules\Document\Enums\DocumentMimeCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'uploader_user_id', 'original_filename', 'storage_path', 'mime_type',
    'size_bytes', 'entity_type', 'entity_id', 'version_number',
    'root_document_id', 'parent_document_id', 'description',
])]
class Document extends TenantModel
{
    use HasFactory;
    use SoftDeletes;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'entity_type' => DocumentEntityType::class,
            'size_bytes' => 'integer',
            'version_number' => 'integer',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_document_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'root_document_id')->orderBy('version_number');
    }

    public function nextVersion(): HasOne
    {
        return $this->hasOne(self::class, 'parent_document_id');
    }

    public function scopeCurrentVersions($query)
    {
        return $query->whereDoesntHave('nextVersion');
    }

    public function mimeCategory(): DocumentMimeCategory
    {
        return DocumentMimeCategory::fromMimeType($this->mime_type);
    }
}
