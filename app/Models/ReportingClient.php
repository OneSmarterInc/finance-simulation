<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * U3: an OAuth client of the reporting connector. "cimd" is Claude's published
 * identity (client_id is the URL of its metadata document); "dcr" was
 * registered through Dynamic Client Registration.
 *
 * @property list<string> $redirect_uris
 */
class ReportingClient extends Model
{
    use HasUuids;

    public const CIMD = 'cimd';

    public const DCR = 'dcr';

    protected $fillable = ['client_id_hash', 'client_id', 'kind', 'name', 'redirect_uris', 'last_used_at'];

    protected function casts(): array
    {
        return ['redirect_uris' => 'array', 'last_used_at' => 'datetime'];
    }

    public function connections(): HasMany
    {
        return $this->hasMany(ReportingConnection::class, 'client_id');
    }

    /** The name an admin sees: the client's own, else its host. */
    public function label(): string
    {
        if (filled($this->name)) {
            return (string) $this->name;
        }
        $host = parse_url((string) ($this->redirect_uris[0] ?? $this->client_id), PHP_URL_HOST);

        return $host ? (string) $host : 'Unnamed client';
    }
}
