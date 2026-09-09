<?php

declare(strict_types=1);

/** Helper function to add a wildcard parameter to the route */
function w($base, $param)
{
    return array_merge($base, [
        'requirements' => [$param => '.*'],
        'defaults' => [$param => ''],
    ]);
}

return [
    'routes' => [
        // Vue routes for deep links
        ['name' => 'Page#main', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'Page#favorites', 'url' => '/favorites', 'verb' => 'GET'],
        ['name' => 'Page#videos', 'url' => '/videos', 'verb' => 'GET'],
        ['name' => 'Page#clips', 'url' => '/clips', 'verb' => 'GET'],
        ['name' => 'Page#stories', 'url' => '/stories', 'verb' => 'GET'],
        ['name' => 'Page#archive', 'url' => '/archive', 'verb' => 'GET'],
        ['name' => 'Page#thisday', 'url' => '/thisday', 'verb' => 'GET'],
        ['name' => 'Page#map', 'url' => '/map', 'verb' => 'GET'],
        ['name' => 'Page#explore', 'url' => '/explore', 'verb' => 'GET'],
        ['name' => 'Page#nxsetup', 'url' => '/nxsetup', 'verb' => 'GET'],

        // Routes with params
        w(['name' => 'Page#folder', 'url' => '/folders/{path}', 'verb' => 'GET'], 'path'),
        w(['name' => 'Page#albums', 'url' => '/albums/{id}', 'verb' => 'GET'], 'id'),
        w(['name' => 'Page#recognize', 'url' => '/recognize/{name}', 'verb' => 'GET'], 'name'),
        w(['name' => 'Page#facerecognition', 'url' => '/facerecognition/{name}', 'verb' => 'GET'], 'name'),
        w(['name' => 'Page#places', 'url' => '/places/{id}', 'verb' => 'GET'], 'id'),
        w(['name' => 'Page#tags', 'url' => '/tags/{name}', 'verb' => 'GET'], 'name'),
        // fork pages (direct links / reloads)
        ['name' => 'Page#peopleReview', 'url' => '/people-review', 'verb' => 'GET'],
        w(['name' => 'Page#similar', 'url' => '/similar/{name}', 'verb' => 'GET'], 'name'),
        w(['name' => 'Page#events', 'url' => '/events/{name}', 'verb' => 'GET'], 'name'),
        w(['name' => 'Page#search', 'url' => '/search/{q}', 'verb' => 'GET'], 'q'),

        // Public folder share
        w(['name' => 'Public#showAuthenticate', 'url' => '/s/{token}/authenticate/{redirect}', 'verb' => 'GET'], 'redirect'),
        w(['name' => 'Public#authenticate', 'url' => '/s/{token}/authenticate/{redirect}', 'verb' => 'POST'], 'redirect'),
        w(['name' => 'Public#showShare', 'url' => '/s/{token}/{path}', 'verb' => 'GET'], 'path'),

        // Public album share
        ['name' => 'PublicAlbum#showShare', 'url' => '/a/{token}', 'verb' => 'GET'],
        ['name' => 'PublicAlbum#download', 'url' => '/a/{token}/download', 'verb' => 'GET'],

        // API Routes
        ['name' => 'Days#days', 'url' => '/api/days', 'verb' => 'GET'],
        ['name' => 'Days#day', 'url' => '/api/days', 'verb' => 'POST'],
        ['name' => 'Days#dayGet', 'url' => '/api/days/{id}', 'verb' => 'GET'],
        ['name' => 'Folders#sub', 'url' => '/api/folders/sub', 'verb' => 'GET'],

        ['name' => 'Clusters#list', 'url' => '/api/clusters/{backend}', 'verb' => 'GET'],
        ['name' => 'Clusters#preview', 'url' => '/api/clusters/{backend}/preview', 'verb' => 'GET'],
        ['name' => 'Clusters#setCover', 'url' => '/api/clusters/{backend}/set-cover', 'verb' => 'POST'],
        ['name' => 'Clusters#download', 'url' => '/api/clusters/{backend}/download', 'verb' => 'POST'],
        // literal routes first: {clusterId} would swallow them
        ['name' => 'PersonAlbums#forAlbum', 'url' => '/api/person-albums/album', 'verb' => 'GET'],
        ['name' => 'PersonAlbums#setOptions', 'url' => '/api/person-albums/album', 'verb' => 'POST'],
        ['name' => 'PersonAlbums#get', 'url' => '/api/person-albums/{clusterId}', 'verb' => 'GET'],
        ['name' => 'PersonAlbums#create', 'url' => '/api/person-albums/{clusterId}', 'verb' => 'POST'],
        ['name' => 'PersonAlbums#unlink', 'url' => '/api/person-albums/{clusterId}', 'verb' => 'DELETE'],
        ['name' => 'Events#rebuild', 'url' => '/api/events/rebuild', 'verb' => 'POST'],
        ['name' => 'Events#createAlbum', 'url' => '/api/events/{eventId}/album', 'verb' => 'POST'],
        ['name' => 'Burst#video', 'url' => '/api/burst/video', 'verb' => 'POST'],
        ['name' => 'Admin#musicTest', 'url' => '/api/admin/music-test', 'verb' => 'POST'],
        ['name' => 'Admin#musicStatus', 'url' => '/api/music/status', 'verb' => 'GET'],
        // videos in the making: the person's own, and (admin) everyone's
        ['name' => 'VideoJobs#list', 'url' => '/api/videos/jobs', 'verb' => 'GET'],
        ['name' => 'VideoJobs#cancel', 'url' => '/api/videos/jobs/{id}/cancel', 'verb' => 'POST'],
        ['name' => 'VideoJobs#retry', 'url' => '/api/videos/jobs/{id}/retry', 'verb' => 'POST'],
        ['name' => 'VideoJobs#delete', 'url' => '/api/videos/jobs/{id}', 'verb' => 'DELETE'],
        ['name' => 'VideoJobs#listAll', 'url' => '/api/admin/videos/jobs', 'verb' => 'GET'],
        ['name' => 'VideoJobs#cancelAny', 'url' => '/api/admin/videos/jobs/{id}/cancel', 'verb' => 'POST'],
        ['name' => 'VideoJobs#retryAny', 'url' => '/api/admin/videos/jobs/{id}/retry', 'verb' => 'POST'],
        ['name' => 'VideoJobs#deleteAny', 'url' => '/api/admin/videos/jobs/{id}', 'verb' => 'DELETE'],
        ['name' => 'VideoJobs#pick', 'url' => '/api/clips/pick', 'verb' => 'POST'],
        ['name' => 'VideoJobs#musicPick', 'url' => '/api/clips/music', 'verb' => 'GET'],
        ['name' => 'VideoJobs#removeWithFile', 'url' => '/api/clips/{id}/remove', 'verb' => 'POST'],

        // stories: photos shown one after the other, full screen
        ['name' => 'Stories#list', 'url' => '/api/stories', 'verb' => 'GET'],
        ['name' => 'Stories#create', 'url' => '/api/stories', 'verb' => 'POST'],
        ['name' => 'Stories#photos', 'url' => '/api/stories/{id}', 'verb' => 'GET'],
        ['name' => 'Stories#rename', 'url' => '/api/stories/{id}', 'verb' => 'PATCH'],
        ['name' => 'Stories#delete', 'url' => '/api/stories/{id}', 'verb' => 'DELETE'],
        ['name' => 'Stories#seen', 'url' => '/api/stories/{id}/seen', 'verb' => 'POST'],

        ['name' => 'Tags#set', 'url' => '/api/tags/set/{id}', 'verb' => 'PATCH'],

        ['name' => 'Map#clusters', 'url' => '/api/map/clusters', 'verb' => 'GET'],
        ['name' => 'Map#init', 'url' => '/api/map/init', 'verb' => 'GET'],

        ['name' => 'Archive#archive', 'url' => '/api/archive/{id}', 'verb' => 'PATCH'],

        ['name' => 'Image#preview', 'url' => '/api/image/preview/{id}', 'verb' => 'GET'],
        ['name' => 'Image#multipreview', 'url' => '/api/image/multipreview', 'verb' => 'POST'],
        ['name' => 'Image#info', 'url' => '/api/image/info/{id}', 'verb' => 'GET'],
        ['name' => 'Image#setExif', 'url' => '/api/image/set-exif/{id}', 'verb' => 'PATCH'],
        ['name' => 'Image#decodable', 'url' => '/api/image/decodable/{id}', 'verb' => 'GET'],
        ['name' => 'Image#editImage', 'url' => '/api/image/edit/{id}', 'verb' => 'PUT'],
        ['name' => 'Image#deleteFile', 'url' => '/api/image/delete/{id}', 'verb' => 'DELETE'],

        ['name' => 'Video#transcode', 'url' => '/api/video/transcode/{client}/{fileid}/{profile}', 'verb' => 'GET'],
        ['name' => 'Video#livephoto', 'url' => '/api/video/livephoto/{fileid}', 'verb' => 'GET'],

        ['name' => 'Download#request', 'url' => '/api/download', 'verb' => 'POST'],
        ['name' => 'Download#file', 'url' => '/api/download/{handle}', 'verb' => 'GET'],
        ['name' => 'Download#one', 'url' => '/api/stream/{fileid}', 'verb' => 'GET'],

        ['name' => 'Share#links', 'url' => '/api/share/links', 'verb' => 'GET'],
        ['name' => 'Share#createNode', 'url' => '/api/share/node', 'verb' => 'POST'],
        ['name' => 'Share#deleteShare', 'url' => '/api/share/delete', 'verb' => 'POST'],

        // Config
        ['name' => 'Other#setUserConfig', 'url' => '/api/config/{key}', 'verb' => 'PUT'],
        ['name' => 'Other#getUserConfig', 'url' => '/api/config', 'verb' => 'GET'],
        ['name' => 'Other#describeApi', 'url' => '/api/describe', 'verb' => 'GET'],

        // Admin
        ['name' => 'Admin#getSystemStatus', 'url' => '/api/system-status', 'verb' => 'GET'],
        ['name' => 'Admin#getSystemConfig', 'url' => '/api/system-config', 'verb' => 'GET'],
        ['name' => 'Admin#setSystemConfig', 'url' => '/api/system-config/{key}', 'verb' => 'PUT'],
        ['name' => 'Admin#getFailureLogs', 'url' => '/api/failure-logs', 'verb' => 'GET'],
        ['name' => 'Admin#placesSetup', 'url' => '/api/occ/places-setup', 'verb' => 'POST'],
        ['name' => 'Admin#eventsRebuild', 'url' => '/api/admin/events-rebuild', 'verb' => 'POST'],
        ['name' => 'Admin#personAlbumsSync', 'url' => '/api/admin/person-albums-sync', 'verb' => 'POST'],
        ['name' => 'Admin#weeklyRecapTest', 'url' => '/api/admin/weekly-recap-test', 'verb' => 'POST'],
        ['name' => 'Admin#indexNow', 'url' => '/api/admin/index', 'verb' => 'POST'],
        ['name' => 'Admin#cleanupStatus', 'url' => '/api/admin/cleanup', 'verb' => 'GET'],
        ['name' => 'Admin#cleanupConfig', 'url' => '/api/admin/cleanup/config', 'verb' => 'PUT'],
        ['name' => 'Admin#cleanupRun', 'url' => '/api/admin/cleanup/run', 'verb' => 'POST'],

        // Service worker and assets
        ['name' => 'Other#static', 'url' => '/static/{name}', 'verb' => 'GET'],
    ],
];
