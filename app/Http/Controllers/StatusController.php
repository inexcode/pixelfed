<?php

namespace App\Http\Controllers;

use App\Jobs\ImageOptimizePipeline\ImageOptimize;
use App\Jobs\StatusPipeline\NewStatusPipeline;
use App\Jobs\StatusPipeline\StatusDelete;
use App\Jobs\SharePipeline\SharePipeline;
use App\AccountInterstitial;
use App\Media;
use App\Profile;
use App\Status;
use App\StatusView;
use App\Transformer\ActivityPub\StatusTransformer;
use App\Transformer\ActivityPub\Verb\Note;
use App\User;
use Auth, Cache;
use Illuminate\Http\Request;
use League\Fractal;
use App\Util\Media\Filter;
use Illuminate\Support\Str;
use App\Services\HashidService;
use App\Services\StatusService;

class StatusController extends Controller
{
    public function show(Request $request, $username, int $id)
    {
        $user = Profile::whereNull('domain')->whereUsername($username)->firstOrFail();

        if($user->status != null) {
            return ProfileController::accountCheck($user);
        }

        $status = Status::whereProfileId($user->id)
                ->whereNull('reblog_of_id')
                ->whereIn('scope', ['public','unlisted', 'private'])
                ->findOrFail($id);

        if($status->uri || $status->url) {
            $url = $status->uri ?? $status->url;
            if(ends_with($url, '/activity')) {
                $url = str_replace('/activity', '', $url);
            }
            return redirect($url);
        }

        if($status->visibility == 'private' || $user->is_private) {
            if(!Auth::check()) {
                abort(404);
            }
            $pid = Auth::user()->profile;
            if($user->followedBy($pid) == false && $user->id !== $pid->id && Auth::user()->is_admin == false) {
                abort(404);
            }
        }

        if($status->type == 'archived') {
            if(Auth::user()->profile_id !== $status->profile_id) {
                abort(404);
            }
        }

        if($request->user() && $request->user()->profile_id != $status->profile_id) {
            StatusView::firstOrCreate([
                'status_id' => $status->id,
                'status_profile_id' => $status->profile_id,
                'profile_id' => $request->user()->profile_id
            ]);
        }

        if ($request->wantsJson() && config('federation.activitypub.enabled')) {
            return $this->showActivityPub($request, $status);
        }

        $template = $status->in_reply_to_id ? 'status.reply' : 'status.show';
        // $template = $status->type === 'video' &&
        //     $request->has('video_beta') && 
        //     $request->video_beta == 1 &&
        //     $request->user() ?
        //     'status.show_video' : 'status.show';

        return view($template, compact('user', 'status'));
    }

    public function shortcodeRedirect(Request $request, $id)
    {
        abort_if(strlen($id) < 5, 404);
        if(!Auth::check()) {
            return redirect('/login?next='.urlencode('/' . $request->path()));
        }
        $id = HashidService::decode($id);
        $status = Status::find($id);
        if(!$status) {
            return redirect('/404');
        }
        return redirect($status->url());
    }

    public function showId(int $id)
    {
        abort(404);
        $status = Status::whereNull('reblog_of_id')
                ->whereIn('scope', ['public', 'unlisted'])
                ->findOrFail($id);
        return redirect($status->url());
    }

    public function showEmbed(Request $request, $username, int $id)
    {
        $profile = Profile::whereNull(['domain','status'])
            ->whereIsPrivate(false)
            ->whereUsername($username)
            ->first();
        if(!$profile) {
            $content = view('status.embed-removed');
            return response($content)->header('X-Frame-Options', 'ALLOWALL');
        }
        $status = Status::whereProfileId($profile->id)
            ->whereNull('uri')
            ->whereScope('public')
            ->whereIsNsfw(false)
            ->whereIn('type', ['photo', 'video','photo:album'])
            ->find($id);
        if(!$status) {
            $content = view('status.embed-removed');
            return response($content)->header('X-Frame-Options', 'ALLOWALL');
        }
        $showLikes = $request->filled('likes') && $request->likes == true;
        $showCaption = $request->filled('caption') && $request->caption !== false;
        $layout = $request->filled('layout') && $request->layout == 'compact' ? 'compact' : 'full';
        $content = view('status.embed', compact('status', 'showLikes', 'showCaption', 'layout'));
        return response($content)->withHeaders(['X-Frame-Options' => 'ALLOWALL']);
    }

    public function showObject(Request $request, $username, int $id)
    {
        $user = Profile::whereNull('domain')->whereUsername($username)->firstOrFail();

        if($user->status != null) {
            return ProfileController::accountCheck($user);
        }

        $status = Status::whereProfileId($user->id)
                ->whereNotIn('visibility',['draft','direct'])
                ->findOrFail($id);

        abort_if($status->uri, 404);

        if($status->visibility == 'private' || $user->is_private) {
            if(!Auth::check()) {
                abort(403);
            }
            $pid = Auth::user()->profile;
            if($user->followedBy($pid) == false && $user->id !== $pid->id) {
                abort(403);
            }
        }

        return $this->showActivityPub($request, $status);
    }

    public function compose()
    {
        $this->authCheck();

        return view('status.compose');
    }

    public function store(Request $request)
    {
        return;
    }

    public function delete(Request $request)
    {
        $this->authCheck();

        $this->validate($request, [
          'item'  => 'required|integer|min:1',
        ]);

        $status = Status::findOrFail($request->input('item'));

        $user = Auth::user();

        if($status->profile_id != $user->profile->id && 
            $user->is_admin == true &&
            $status->uri == null
        ) {
            $media = $status->media;

            $ai = new AccountInterstitial;
            $ai->user_id = $status->profile->user_id;
            $ai->type = 'post.removed';
            $ai->view = 'account.moderation.post.removed';
            $ai->item_type = 'App\Status';
            $ai->item_id = $status->id;
            $ai->has_media = (bool) $media->count();
            $ai->blurhash = $media->count() ? $media->first()->blurhash : null;
            $ai->meta = json_encode([
                'caption' => $status->caption,
                'created_at' => $status->created_at,
                'type' => $status->type,
                'url' => $status->url(),
                'is_nsfw' => $status->is_nsfw,
                'scope' => $status->scope,
                'reblog' => $status->reblog_of_id,
                'likes_count' => $status->likes_count,
                'reblogs_count' => $status->reblogs_count,
            ]);
            $ai->save();

            $u = $status->profile->user;
            $u->has_interstitial = true;
            $u->save();
        }

        Cache::forget('_api:statuses:recent_9:' . $status->profile_id);
        Cache::forget('profile:status_count:' . $status->profile_id);
        Cache::forget('profile:embed:' . $status->profile_id);
        StatusService::del($status->id);
        if ($status->profile_id == $user->profile->id || $user->is_admin == true) {
            Cache::forget('profile:status_count:'.$status->profile_id);
            StatusDelete::dispatch($status);
        }

        if($request->wantsJson()) {
            return response()->json(['Status successfully deleted.']);
        } else {
            return redirect($user->url());
        }
    }

    public function storeShare(Request $request)
    {
        $this->authCheck();
        
        $this->validate($request, [
          'item'    => 'required|integer|min:1',
        ]);

        $user = Auth::user();
        $profile = $user->profile;
        $status = Status::withCount('shares')
            ->whereIn('scope', ['public', 'unlisted'])
            ->findOrFail($request->input('item'));

        $count = $status->shares()->count();

        $exists = Status::whereProfileId(Auth::user()->profile->id)
                  ->whereReblogOfId($status->id)
                  ->count();
        if ($exists !== 0) {
            $shares = Status::whereProfileId(Auth::user()->profile->id)
                  ->whereReblogOfId($status->id)
                  ->get();
            foreach ($shares as $share) {
                $share->delete();
                $count--;
            }
        } else {
            $share = new Status();
            $share->profile_id = $profile->id;
            $share->reblog_of_id = $status->id;
            $share->in_reply_to_profile_id = $status->profile_id;
            $share->save();
            $count++;
            SharePipeline::dispatch($share);
        }
 
        if($count >= 0) {
            $status->reblogs_count = $count;
            $status->save();
        }
 
        Cache::forget('status:'.$status->id.':sharedby:userid:'.$user->id);
        StatusService::del($status->id);
        
        if ($request->ajax()) {
            $response = ['code' => 200, 'msg' => 'Share saved', 'count' => $count];
        } else {
            $response = redirect($status->url());
        }

        return $response;
    }

    public function showActivityPub(Request $request, $status)
    {
        $fractal = new Fractal\Manager();
        $resource = new Fractal\Resource\Item($status, new Note());
        $res = $fractal->createData($resource)->toArray();

        return response()->json($res['data'], 200, ['Content-Type' => 'application/activity+json'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    }

    public function edit(Request $request, $username, $id)
    {
        $this->authCheck();
        $user = Auth::user()->profile;
        $status = Status::whereProfileId($user->id)
                ->with(['media'])
                ->where('created_at', '>', now()->subHours(24))
                ->findOrFail($id);
        return view('status.edit', compact('user', 'status'));
    }

    public function editStore(Request $request, $username, $id)
    {
        $this->authCheck();
        $user = Auth::user()->profile;
        $status = Status::whereProfileId($user->id)
                ->with(['media'])
                ->where('created_at', '>', now()->subHours(24))
                ->findOrFail($id);

        $this->validate($request, [
          'id'      => 'required|integer|min:1',
          'caption' => 'nullable',
          'filter'  => 'nullable|alpha_dash|max:30',
        ]);

        $id = $request->input('id');
        $caption = $request->input('caption');
        $filter = $request->input('filter');

        $media = Media::whereProfileId($user->id)
            ->whereStatusId($status->id)
            ->findOrFail($id);

        $changed = false;

        if ($media->caption != $caption) {
            $media->caption = $caption;
            $changed = true;
        }

        if ($media->filter_class != $filter && in_array($filter, Filter::classes())) {
            $media->filter_class = $filter;
            $changed = true;
        }

        if ($changed === true) {
            $media->save();
            Cache::forget('status:transformer:media:attachments:'.$media->status_id);
        }

        return response()->json([], 200);
    }

    protected function authCheck()
    {
        if (Auth::check() == false) {
            abort(403);
        }
    }

    protected function validateVisibility($visibility)
    {
        $allowed = ['public', 'unlisted', 'private'];
        return in_array($visibility, $allowed) ? $visibility : 'public';
    }

    public static function mimeTypeCheck($mimes)
    {
        $allowed = explode(',', config('pixelfed.media_types'));
        $count = count($mimes);
        $photos = 0;
        $videos = 0;
        foreach($mimes as $mime) {
            if(in_array($mime, $allowed) == false && $mime !== 'video/mp4') {
                continue;
            }
            if(str_contains($mime, 'image/')) {
                $photos++;
            }
            if(str_contains($mime, 'video/')) {
                $videos++;
            }
        }
        if($photos == 1 && $videos == 0) {
            return 'photo';
        }
        if($videos == 1 && $photos == 0) {
            return 'video';
        }
        if($photos > 1 && $videos == 0) {
            return 'photo:album';
        }
        if($videos > 1 && $photos == 0) {
            return 'video:album';
        }
        if($photos >= 1 && $videos >= 1) {
            return 'photo:video:album';
        }
    }

    public function toggleVisibility(Request $request) {
        $this->authCheck();
        $this->validate($request, [
            'item' => 'required|string|min:1|max:20',
            'disableComments' => 'required|boolean'
        ]);

        $user = Auth::user();
        $id = $request->input('item');
        $state = $request->input('disableComments');

        $status = Status::findOrFail($id);

        if($status->profile_id != $user->profile->id && $user->is_admin == false) {
            abort(403);
        }

        $status->comments_disabled = $status->comments_disabled == true ? false : true;
        $status->save();

        return response()->json([200]);
    }

    public function storeView(Request $request)
    {
        abort_if(!$request->user(), 403);

        $this->validate($request, [
            'status_id' => 'required|integer|exists:statuses,id',
            'profile_id' => 'required|integer|exists:profiles,id'
        ]);

        $sid = (int) $request->input('status_id');
        $pid = (int) $request->input('profile_id');

        StatusView::firstOrCreate([
                'status_id' => $sid,
                'status_profile_id' => $pid,
                'profile_id' => $request->user()->profile_id
        ]);

        return response()->json(1);
    }
}
