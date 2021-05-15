<?php

namespace App\Util\Site;

use Cache;
use Illuminate\Support\Str;

class Config {

	public static function get() {
		return Cache::remember('api:site:configuration:_v0.2', now()->addMinutes(5), function() {
			return [
				'open_registration' => (bool) config_cache('pixelfed.open_registration'),
				'uploader' => [
					'max_photo_size' => config('pixelfed.max_photo_size'),
					'max_caption_length' => config('pixelfed.max_caption_length'),
					'album_limit' => config_cache('pixelfed.max_album_length'),
					'image_quality' => config_cache('pixelfed.image_quality'),

					'max_collection_length' => config('pixelfed.max_collection_length', 18),

					'optimize_image' => config('pixelfed.optimize_image'),
					'optimize_video' => config('pixelfed.optimize_video'),

					'media_types' => config_cache('pixelfed.media_types'),
					'enforce_account_limit' => config_cache('pixelfed.enforce_account_limit')
				],

				'activitypub' => [
					'enabled' => config_cache('federation.activitypub.enabled'),
					'remote_follow' => config('federation.activitypub.remoteFollow')
				],

				'ab' => [
					'lc' => config('exp.lc'),
					'rec' => config('exp.rec'),
					'loops' => config('exp.loops'),
					'top' => config('exp.top')
				],

				'site' => [
					'name' => config_cache('app.name'),
					'domain' => config('pixelfed.domain.app'),
					'url'    => config('app.url'),
					'description' => config_cache('app.short_description')
				],

				'username' => [
					'remote' => [
						'formats' => config('instance.username.remote.formats'),
						'format' => config('instance.username.remote.format'),
						'custom' => config('instance.username.remote.custom')
					]
				],

				'features' => [
					'mobile_apis' => config_cache('pixelfed.oauth_enabled'),
					'circles' => false,
					'stories' => config_cache('instance.stories.enabled'),
					'video'	=> Str::contains(config_cache('pixelfed.media_types'), 'video/mp4'),
					'import' => [
						'instagram' => config_cache('pixelfed.import.instagram.enabled'),
						'mastodon' => false,
						'pixelfed' => false
					],
					'label' => [
						'covid' => [
							'enabled' => config('instance.label.covid.enabled'),
							'org' => config('instance.label.covid.org'),
							'url' => config('instance.label.covid.url'),
						]
					]
				]
			];
		});
	}

	public static function json() {
		return json_encode(self::get(), JSON_FORCE_OBJECT);
	}
}
