<?php
// Fake Twitch for test/run.sh (php -S): the token endpoint, Helix /streams and /users, every call logged
// in $TWITCH_LOG. Credentials expected: test-id / test-secret; token handed out: tok123.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents(getenv('TWITCH_LOG') ?: '/tmp/twitch.log', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
header('Content-Type: application/json');
if ($path === '/oauth2/token') {
    if (($_POST['client_id'] ?? '') !== 'test-id' || ($_POST['client_secret'] ?? '') !== 'test-secret' || ($_POST['grant_type'] ?? '') !== 'client_credentials') {
        http_response_code(400);
        echo '{"status":400,"message":"invalid client"}';
        return;
    }
    echo json_encode(['access_token' => 'tok123', 'expires_in' => 5000000, 'token_type' => 'bearer']);
    return;
}
if ($path === '/helix/streams') {
    if (($_SERVER['HTTP_CLIENT_ID'] ?? '') !== 'test-id' || ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer tok123') {
        http_response_code(401);
        echo '{"status":401,"message":"invalid token"}';
        return;
    }
    echo json_encode(['data' => [
        ['user_login' => 'third', 'type' => 'live', 'title' => '', 'game_name' => '', 'viewer_count' => 1,
            'thumbnail_url' => 'https://static-cdn.jtvnw.net/previews-ttv/live_user_third-{width}x{height}.jpg'],
        ['user_login' => 'other_chan', 'type' => 'live', 'title' => 'Draft <b>Foundations</b> & co', 'game_name' => 'Magic: The Gathering', 'viewer_count' => 1234,
            'thumbnail_url' => 'https://static-cdn.jtvnw.net/previews-ttv/live_user_other_chan-{width}x{height}.jpg'],
        // Demo channels of the README captures (test/pages/demo)
        ['user_login' => 'pixel_forge', 'type' => 'live', 'title' => 'Any% practice, then viewer races', 'game_name' => 'Celeste', 'viewer_count' => 2841,
            'thumbnail_url' => 'https://static-cdn.jtvnw.net/previews-ttv/live_user_pixel_forge-{width}x{height}.jpg'],
        ['user_login' => 'night_owl', 'type' => 'live', 'title' => 'Late chat and cozy builds', 'game_name' => 'Minecraft', 'viewer_count' => 57,
            'thumbnail_url' => 'https://static-cdn.jtvnw.net/previews-ttv/live_user_night_owl-{width}x{height}.jpg'],
    ], 'pagination' => (object) []]);
    return;
}
if ($path === '/helix/users') {
    if (($_SERVER['HTTP_CLIENT_ID'] ?? '') !== 'test-id' || ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer tok123') {
        http_response_code(401);
        echo '{"status":401,"message":"invalid token"}';
        return;
    }
    // exeprod: offline image; other_chan: an image outside Twitch's CDN, to be dropped; third: avatar only
    echo json_encode(['data' => [
        ['login' => 'exeprod', 'profile_image_url' => 'https://static-cdn.jtvnw.net/jtv_user_pictures/exeprod-profile_image-300x300.png',
            'offline_image_url' => 'https://static-cdn.jtvnw.net/jtv_user_pictures/exeprod-channel_offline_image-1920x1080.png'],
        ['login' => 'other_chan', 'profile_image_url' => 'https://evil.example/x.png" onerror="alert(1)', 'offline_image_url' => ''],
        ['login' => 'third', 'profile_image_url' => 'https://static-cdn.jtvnw.net/jtv_user_pictures/third-profile_image-300x300.png', 'offline_image_url' => ''],
        ['login' => 'pixel_forge', 'profile_image_url' => 'https://static-cdn.jtvnw.net/jtv_user_pictures/pixel_forge-profile_image-300x300.png', 'offline_image_url' => ''],
        ['login' => 'night_owl', 'profile_image_url' => 'https://static-cdn.jtvnw.net/jtv_user_pictures/night_owl-profile_image-300x300.png', 'offline_image_url' => ''],
        ['login' => 'studio_moss', 'profile_image_url' => 'https://static-cdn.jtvnw.net/jtv_user_pictures/studio_moss-profile_image-300x300.png',
            'offline_image_url' => 'https://static-cdn.jtvnw.net/jtv_user_pictures/studio_moss-channel_offline_image-1920x1080.png'],
    ]]);
    return;
}
http_response_code(404);
echo '{}';
