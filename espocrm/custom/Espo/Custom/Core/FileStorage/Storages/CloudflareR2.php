<?php

namespace Espo\Custom\Core\FileStorage\Storages;

use Psr\Http\Message\StreamInterface;
use AsyncAws\S3\S3Client;
use League\Flysystem\FilesystemException;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use GuzzleHttp\Psr7\Stream;

use Espo\Core\FileStorage\Attachment;
use Espo\Core\FileStorage\Storage;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config;

use RuntimeException;

/**
 * S3-API-compatible driver for Cloudflare R2, following the same
 * AsyncAws/Flysystem approach as the stock (unused) Espo\Core\FileStorage\
 * Storages\AwsS3 driver - R2 speaks the S3 API, so the same client/adapter
 * stack works unmodified, just pointed at R2's endpoint.
 *
 * The one substantive difference from AwsS3: every object key is prefixed
 * with the attachment's tenant_id ("{tenantId}/{sourceId}") rather than
 * just the bare source ID. Access control is still enforced by the app
 * layer (ACL checks in the entry points that serve file bytes) exactly as
 * it is for local storage today - this prefix is an added layer of
 * organization/defense, not a replacement for that.
 */
class CloudflareR2 implements Storage
{
    private Filesystem $filesystem;

    public function __construct(Config $config, private TenantContextStore $tenantContextStore)
    {
        $accountId = $config->get('cloudflareR2Storage.accountId') ?? null;
        $bucketName = $config->get('cloudflareR2Storage.bucketName') ?? null;
        $credentials = $config->get('cloudflareR2Storage.credentials') ?? null;

        if (!$accountId) {
            throw new RuntimeException("Cloudflare R2 account ID is not specified in config.");
        }

        if (!$bucketName) {
            throw new RuntimeException("Cloudflare R2 bucket name is not specified in config.");
        }

        $clientOptions = [
            'region' => 'auto',
            'endpoint' => "https://{$accountId}.r2.cloudflarestorage.com",
            'pathStyleEndpoint' => true,
        ];

        if ($credentials && is_array($credentials)) {
            $clientOptions['accessKeyId'] = $credentials['key'] ?? null;
            $clientOptions['accessKeySecret'] = $credentials['secret'] ?? null;
        }

        $client = new S3Client($clientOptions);
        $adapter = new AsyncAwsS3Adapter($client, $bucketName);

        $this->filesystem = new Filesystem($adapter);
    }

    /**
     * Every attachment's object key lives under its own tenant's prefix.
     * Tenant scoping in this app is stamped at the SQL layer, not on the
     * PHP entity - confirmed directly: a just-created entity's own
     * tenant_id field reads back as null on the *same* in-memory object
     * even right after saveEntity() returns, but is correctly populated on
     * any *fresh* fetch (getEntityById, a repository find/where query) of
     * that same row. That fresh-fetch case covers both real callers of
     * this driver that read/write *existing* attachments (the async
     * MoveToStorage job re-fetches by ID; a maintenance script iterating
     * every tenant's rows via find() gets each one hydrated correctly).
     * The one gap is the synchronous "direct contents" attachment-create
     * path (Espo\Repositories\Attachment::processBeforeSaveNew(), which
     * calls putContents() on the same never-refetched object mid-beforeSave)
     * - for that case only, fall back to the ambient TenantContextStore,
     * which is reliably set to the real tenant for any live request.
     *
     * Deliberately throws rather than falling back to an "unscoped" bucket
     * of its own - found the hard way that a silent fallback lets a script
     * running under platform/no-tenant context (TenantContextStore returns
     * null there, same as an unhydrated entity) misfile every single file
     * it touches without any error, which is exactly what happened during
     * this driver's own initial data migration. Loud and immediate beats
     * silently wrong.
     */
    private function key(Attachment $attachment): string
    {
        $tenantId = method_exists($attachment, 'get') ? $attachment->get('tenantId') : null;
        $tenantId = $tenantId ?: $this->tenantContextStore->getTenantId();

        if (!$tenantId) {
            throw new RuntimeException(
                "Cannot resolve a tenant for attachment {$attachment->getSourceId()} - " .
                "refusing to write/read it without a known tenant scope."
            );
        }

        return $tenantId . '/' . $attachment->getSourceId();
    }

    public function unlink(Attachment $attachment): void
    {
        try {
            $this->filesystem->delete($this->key($attachment));
        } catch (FilesystemException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    public function exists(Attachment $attachment): bool
    {
        try {
            return $this->filesystem->fileExists($this->key($attachment));
        } catch (FilesystemException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    public function getSize(Attachment $attachment): int
    {
        try {
            return $this->filesystem->fileSize($this->key($attachment));
        } catch (FilesystemException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    public function getStream(Attachment $attachment): StreamInterface
    {
        try {
            $resource = $this->filesystem->readStream($this->key($attachment));
        } catch (FilesystemException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        return new Stream($resource);
    }

    public function putStream(Attachment $attachment, StreamInterface $stream): void
    {
        // League\Flysystem does not support StreamInterface - needs a resource.
        $resource = fopen('php://temp', 'r+');

        if ($resource === false) {
            throw new RuntimeException("Could not open temp.");
        }

        $stream->rewind();

        fwrite($resource, $stream->getContents());
        rewind($resource);

        try {
            $this->filesystem->writeStream($this->key($attachment), $resource);
        } catch (FilesystemException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        fclose($resource);
    }
}
