<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClaimNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->uuid,
            'note'       => $this->note,
            'author'     => [
                'id'   => $this->whenLoaded('author', fn () => $this->author->uuid),
                'name' => $this->whenLoaded('author', fn () => $this->author->name),
            ],
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
