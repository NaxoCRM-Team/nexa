<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . '/' . $relative);
    if ($content === false) throw new RuntimeException("Unable to read {$relative}.");
    return $content;
};
$mustContain = static function (string $needle, string $content, string $message): void {
    if (!str_contains($content, $needle)) throw new RuntimeException($message);
};

$routes = json_decode($read('espocrm/custom/Espo/Custom/Resources/routes.json'), true, flags: JSON_THROW_ON_ERROR);
$service = $read('espocrm/custom/Espo/Custom/Tools/TenantFile/TenantImageLibrary.php');
$api = $read('espocrm/custom/Espo/Custom/Tools/TenantFile/Api/GetImages.php');
$fileApi = $read('espocrm/custom/Espo/Custom/Tools/TenantFile/Api/GetFiles.php');
$fileUploadApi = $read('espocrm/custom/Espo/Custom/Tools/TenantFile/Api/PostFile.php');
$field = $read('espocrm/client/custom/src/views/fields/nexa-rich-text.js');
$imageHelper = $read('espocrm/client/custom/src/helpers/tenant-images.js');
$modal = $read('espocrm/client/custom/src/views/wysiwyg/modals/tenant-image.js');
$template = $read('espocrm/client/custom/res/templates/wysiwyg/modals/tenant-image.tpl');
$fileModal = $read('espocrm/client/custom/src/views/wysiwyg/modals/tenant-file.js');
$fileTemplate = $read('espocrm/client/custom/res/templates/wysiwyg/modals/tenant-file.tpl');
$fileHelper = $read('espocrm/client/custom/src/helpers/tenant-files.js');
$storage = $read('espocrm/custom/Espo/Custom/Core/FileStorage/Storages/CloudflareR2.php');
$styles = $read('espocrm/client/custom/css/crm-workflows.css');

$imageRoute = array_values(array_filter($routes, static fn (array $route): bool =>
    ($route['route'] ?? '') === '/Nexa/files/images' && ($route['method'] ?? '') === 'get'
));
if (count($imageRoute) !== 1 || isset($imageRoute[0]['noAuth'])) {
    throw new RuntimeException('Tenant image discovery must have one authenticated API route.');
}
$uploadRoute = array_values(array_filter($routes, static fn (array $route): bool =>
    ($route['route'] ?? '') === '/Nexa/files/images' && ($route['method'] ?? '') === 'post'
));
if (count($uploadRoute) !== 1 || isset($uploadRoute[0]['noAuth'])) {
    throw new RuntimeException('Tenant image upload must have one authenticated API route.');
}
$fileRoutes = array_values(array_filter($routes, static fn (array $route): bool =>
    ($route['route'] ?? '') === '/Nexa/files' && in_array(($route['method'] ?? ''), ['get', 'post'], true)
));
if (count($fileRoutes) !== 2 || array_filter($fileRoutes, static fn (array $route): bool => isset($route['noAuth']))) {
    throw new RuntimeException('Tenant file discovery and upload must use authenticated API routes.');
}

$mustContain("checkScope('Note', Table::ACTION_CREATE)", $service, 'Tenant files must enforce Note-create permission.');
$mustContain("'role' => Attachment::ROLE_INLINE_ATTACHMENT", $service, 'Only reusable inline attachments may appear in the image library.');
$mustContain("str_starts_with(\$mimeType, 'image/')", $service, 'The library must reject non-image attachments.');
$mustContain('new \\finfo(FILEINFO_MIME_TYPE)', $service, 'Tenant image upload must inspect real file contents.');
$mustContain('MAX_IMAGE_SIZE', $service, 'Tenant image upload must enforce a bounded payload.');
$mustContain('MAX_FILE_SIZE = 25 * 1024 * 1024', $service, 'General tenant files must be limited to 25 MB.');
$mustContain('FILE_TYPES', $service, 'General tenant files must use an explicit safe type allow-list.');
$mustContain('uploadFile', $service . $fileUploadApi, 'General tenant file upload must use the protected service boundary.');
$mustContain('getFilePage', $service . $fileApi, 'General tenant file discovery must use the tenant-scoped service boundary.');
$mustContain("setTargetField('nexaTenantAsset')", $service, 'Reusable tenant images must have an explicit attachment purpose.');
$mustContain('SaveOption::SILENT', $service, 'Tenant image upload must use direct trusted Attachment persistence.');
$mustContain('getRDBRepository(Attachment::ENTITY_TYPE)', $service, 'The library must query the centrally tenant-scoped Attachment repository.');
$mustContain("setHeader('Cache-Control', 'private, no-store')", $api, 'Tenant image discovery must not be cached publicly.');
$mustContain("return \$tenantId . '/' . \$attachment->getSourceId()", $storage, 'Cloudflare R2 objects must remain tenant-prefixed.');
$mustContain("name === 'espoImage' ? 'nexaTenantImage'", $field, 'The Nexa editor must replace the stock image dialog with the tenant library.');
$mustContain("return 'nexaTenantFile'", $field, 'The Nexa editor must replace the stock paperclip with the tenant file library.');
$mustContain("Espo.Ajax.postRequest('Nexa/files'", $field, 'New rich-editor files must use the protected tenant Files upload API.');
$mustContain('data-nexa-file-id', $field . $fileHelper, 'Inserted files must retain a secure Attachment marker.');
$mustContain("this.model?.entityType === 'Email' && this.name === 'body'", $field, 'Email body attachments must use the native composer attachment contract.');
$mustContain('attachmentsIds: attachmentIds', $field, 'Tenant library files must be added to native Email attachments for external delivery.');
$mustContain('attachmentsNames: attachmentNames', $field, 'Email attachment names must remain visible in the native composer.');
$mustContain('Nexa/attachment-file/', $fileHelper, 'Attached file downloads must use authenticated byte retrieval.');
$mustContain("Espo.Ajax.postRequest('Nexa/files/images'", $field, 'New rich-editor images must use the protected tenant Files upload API.');
$mustContain("TenantImages.load(attachment.id)", $field, 'Inserted images must load through authenticated tenant file retrieval.');
$mustContain('data-nexa-attachment-id', $field . $imageHelper, 'Saved rich text must retain an Attachment marker.');
$mustContain('?entryPoint=attachment&id=', $imageHelper, 'Persisted image HTML must retain a compact protected attachment URL.');
$mustContain('Nexa/attachment-file/', $imageHelper, 'Rich content must hydrate through authenticated byte retrieval.');
$mustContain('normalizeHtml', $field . $imageHelper, 'The editor must replace transient image data before persistence.');
$mustContain('nexa-image-resize-handle', $field . $styles, 'Embedded images must expose a keyboard and pointer resize handle.');
$mustContain("Espo.Ajax.getRequest('Nexa/files/images'", $modal, 'The image picker must load tenant-scoped image metadata.');
$mustContain('Nexa/attachment-file/', $modal, 'Image previews must use authenticated byte retrieval.');
$mustContain('data-nexa-image-search', $template, 'The tenant image picker must provide live search.');
$mustContain('data-nexa-image-upload', $template, 'The tenant image picker must provide upload.');
$mustContain('data-nexa-image-previous', $template, 'The tenant image picker must provide pagination.');
$mustContain('.nexa-image-library-grid', $styles, 'The tenant image picker requires a stable responsive grid.');
$mustContain('.nexa-image-library-upload input[type="file"]', $styles, 'The branded upload command must conceal the native file control.');
$mustContain('.modal.nexa-tenant-image-dialog', $styles, 'The tenant image picker must own a modal layer above interaction dialogs.');
$mustContain('z-index: 1220', $styles, 'The tenant image picker must render above Nexa interaction overlays.');
$mustContain("Espo.Ajax.getRequest('Nexa/files'", $fileModal, 'The paperclip picker must search the tenant file library.');
$mustContain('data-nexa-file-upload', $fileTemplate, 'The paperclip picker must allow a new file upload.');
$mustContain('Images up to 8 MB; documents up to 25 MB', $fileTemplate, 'The file picker must state both upload limits.');
$mustContain('.nexa-file-library-list', $styles, 'The tenant file picker needs a stable responsive list.');
$mustContain('.nexa-tenant-file-link', $styles, 'Inserted tenant files need a clear downloadable-link treatment.');
$mustContain('@media (max-width: 767px)', $styles, 'The tenant image picker must adapt on mobile.');

foreach (['tenant-a', 'tenant-b', 'isolation-alpha'] as $literal) {
    if (str_contains($service, $literal) || str_contains($modal, $literal) || str_contains($imageHelper, $literal)) {
        throw new RuntimeException("Tenant file handling must not hardcode {$literal}.");
    }
}

echo "Tenant file library and rich editor contracts passed.\n";
