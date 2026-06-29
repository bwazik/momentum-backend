<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Document\Enums\DocumentEntityType;
use App\Modules\Document\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'public_id' => null,
            'uploader_user_id' => User::factory(),
            'original_filename' => fake()->word().'.pdf',
            'storage_path' => 'test/documents/'.fake()->uuid().'/file.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1024, 10485760),
            'entity_type' => DocumentEntityType::Task,
            'entity_id' => 1,
            'version_number' => 1,
            'root_document_id' => null,
            'parent_document_id' => null,
            'description' => fake()->optional()->sentence(),
        ];
    }

    public function forEntity(DocumentEntityType $entityType, int $entityId): static
    {
        return $this->state([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
    }

    public function asVersion(Document $root): static
    {
        $latest = Document::where('root_document_id', $root->id)
            ->orderByDesc('version_number')
            ->first();

        $parent = $latest ?? $root;

        return $this->state([
            'root_document_id' => $root->id,
            'parent_document_id' => $parent->id,
            'version_number' => $parent->version_number + 1,
        ]);
    }
}
