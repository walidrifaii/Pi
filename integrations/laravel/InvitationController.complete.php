<?php

/**
 * DROP-IN: copy to app/Http/Controllers/Website/InvitationController.php
 *
 * - Ensure public/invitations exists and is writable (test + store image upload).
 * - GuestsImport: use your real namespace (e.g. App\Imports\GuestsImport).
 * - WhatsAppNodeCampaignService: use integrations/laravel/WhatsAppNodeCampaignService.php
 * - APP_URL must be reachable from the Node server for image download.
 *
 * Fixes in this version:
 * - send_test: full form in WhatsApp template + image as media (sendCampaign 4th/5th args)
 * - UTF-8 Arabic templates (not mojibake)
 * - invitations(): undefined $type
 * - Update(): removed broken $validator->fails() after validate()
 * - InvitationGuest class casing; Excel / GuestsImport use lines
 */

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvitationResource;
use App\Models\Invitation;
use App\Models\InvitationGuest;
use App\Models\Template;
use App\Models\TestInvitaion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Api\HelperController;
use App\Services\WhatsAppNodeCampaignService;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\GuestsImport;

class InvitationController extends Controller
{
    protected HelperController $helper;

    protected WhatsAppNodeCampaignService $whatsAppNodeCampaign;

    public function __construct(HelperController $helper, WhatsAppNodeCampaignService $whatsAppNodeCampaign)
    {
        $this->helper = $helper;
        $this->whatsAppNodeCampaign = $whatsAppNodeCampaign;
    }

    public function testInvitation()
    {
        $user = Auth::user();
        $used = TestInvitaion::where('user_id', $user->id)->count();
        $remaining = max(0, TestInvitaion::MAX_PER_USER - $used);

        return view('website.test_invitation', [
            'testInvitesUsed' => $used,
            'testInvitesRemaining' => $remaining,
        ]);
    }

    public function send_test(Request $request)
    {
        $user = Auth::user();

        $used = TestInvitaion::where('user_id', $user->id)->count();
        if ($used >= TestInvitaion::MAX_PER_USER) {
            return redirect()->back()
                ->withInput()
                ->with('error', __('website.test_invitation_limit'));
        }

        $validator = Validator::make($request->all(), [
            'phone' => 'required',
            'user_name' => 'required',
            'title' => 'required',
            'location' => 'required',
            'lang' => 'required',
            'image' => 'required|file|max:2048|mimes:png,jpeg,gif,svg,webp,jpg',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $invitation = new TestInvitaion();
        $invitation->user_id = $user->id;
        $invitation->title = $request->title;
        $invitation->user_name = $request->user_name;
        $invitation->phone = $request->phone;
        $invitation->location = $request->location;
        $invitation->lat = $request->lat;
        $invitation->long = $request->input('long', $request->input('lng'));
        $invitation->lang = $request->lang;

        $image = $request->file('image');
        $image_name = $image->hashName();
        $image->move(public_path('invitations'), $image_name);
        $image_path = url('invitations/' . $image_name);
        $invitation->image = $image_path;
        $invitation->image_path = $image_path;
        $invitation->save();

        $campaignName = 'test-invite-' . $invitation->id;

        $lat = trim((string) ($invitation->lat ?? ''));
        $lng = trim((string) ($invitation->long ?? ''));
        if ($lat === '') {
            $lat = '—';
        }
        if ($lng === '') {
            $lng = '—';
        }

        $langLabel = $invitation->lang === 'en'
            ? 'English'
            : (($invitation->lang === 'ar') ? 'العربية' : (string) $invitation->lang);

        if ($invitation->lang === 'en') {
            $messageTemplate =
                "Hello {name},\n\n" .
                "This is your Zajel trial invitation.\n\n" .
                "Occasion: {title}\n" .
                "Sender name: {sender_name}\n" .
                "Phone: {contact_phone}\n" .
                "Language: {lang_label}\n" .
                "Location: {location}\n" .
                "Coordinates: {lat}, {lng}\n\n" .
                "Invitation image / link:\n{link}";
        } else {
            $messageTemplate =
                "مرحباً {name}،\n\n" .
                "هذه دعوتك التجريبية من زاجل.\n\n" .
                "اسم المناسبة: {title}\n" .
                "اسم مرسل الدعوة: {sender_name}\n" .
                "رقم الهاتف: {contact_phone}\n" .
                "لغة الدعوة: {lang_label}\n" .
                "موقع المناسبة: {location}\n" .
                "الإحداثيات: {lat} ، {lng}\n\n" .
                "صورة الدعوة / الرابط:\n{link}";
        }

        $imagePublicUrl = $invitation->image_path ?: $invitation->image;
        if ($imagePublicUrl !== '' && ! preg_match('#^https?://#i', $imagePublicUrl)) {
            $imagePublicUrl = url(ltrim($imagePublicUrl, '/'));
        }

        $nodeResult = $this->whatsAppNodeCampaign->sendCampaign(
            $campaignName,
            $messageTemplate,
            [[
                'phone' => trim((string) $invitation->phone),
                'name' => (string) $invitation->user_name,
                'link' => (string) ($invitation->image_path ?: $invitation->image),
                'title' => (string) $invitation->title,
                'sender_name' => (string) $invitation->user_name,
                'contact_phone' => trim((string) $invitation->phone),
                'lang' => (string) $invitation->lang,
                'lang_label' => $langLabel,
                'location' => trim((string) ($invitation->location ?? '')),
                'lat' => $lat,
                'lng' => $lng,
            ]],
            $imagePublicUrl ?: null,
            $imagePublicUrl ? 'image' : null
        );

        if (! $nodeResult['success']) {
            return redirect()->back()
                ->withInput()
                ->with('error', $nodeResult['error'] ?? __('website.test_invitation_whatsapp_failed'));
        }

        return redirect()->route('home')->with('success', __('api.success'));
    }

    public function index()
    {
        $user = Auth::user();

        return view('website.invitations.index');
    }

    public function show($id)
    {
        $user = Auth::user();
        $invitation = Invitation::where('user_id', $user->id)->where('id', $id)->first();

        if ($invitation) {
            return view('website.invitations.show')->with(['invitation' => $invitation]);
        }

        return redirect()->back();
    }

    public function invitations($order, $sort)
    {
        $user = Auth::user();

        $type = request()->query('staues');

        $query = Invitation::where('user_id', $user->id)->orderBy($order, $sort);
        if ($type !== null && $type !== '') {
            $query->where('staues', $type);
        }

        $invitations = $query->paginate(12);
        $data['invitations'] = InvitationResource::collection($invitations);
        $data['pagination'] = [
            'total' => $invitations->total(),
            'count' => $invitations->count(),
            'per_page' => $invitations->perPage(),
            'current_page' => $invitations->currentPage(),
            'total_pages' => $invitations->lastPage(),
            'next_page_url' => $invitations->nextPageUrl(),
            'prev_page_url' => $invitations->previousPageUrl(),
        ];

        return $this->helper->sendResponse($data, 'invitations list', true, 200);
    }

    public function report($id, $type)
    {
        $user = Auth::user();
        $invitation = Invitation::where('user_id', $user->id)->where('id', $id)->first();

        if ($invitation) {
            return view('website.invitations.report')->with(['invitation' => $invitation, 'type' => $type]);
        }

        return redirect()->back();
    }

    public function create()
    {
        $user = Auth::user();
        $templates = Template::get();
        if ($user->subscribe == 1) {
            if ($user->count_invitations <= 0) {
                return redirect()->back()->with('error', __('website.You must subscribe to create Invitation'));
            }

            return view('website.invitations.create')->with(['templates' => $templates]);
        }

        return redirect()->back()->with('error', __('website.You must subscribe to create Invitation'));
    }

    public function store(Request $request)
    {
        $validate = $request->validate([
            'name' => 'required',
            'owner_name' => 'required',
            'location' => 'required',
            'lang' => 'required',
            'image' => 'nullable|max:2048|mimes:png,jpeg,gif,svg,webp,jpg',
            'date' => 'required',
            'time' => 'required',
        ]);

        $user = Auth::user();
        if ($user->count_invitations <= 0) {
            return redirect()->back()->with('error', __('website.You must subscribe to create Invitation'));
        }
        if (
            ($request->users && $user->balance <= count($request->users ?? [])) ||
            $user->balance <= ($request->count_guests ?? 0)
        ) {
            return redirect()->back()->with('error', __('website.You must subscribe to create Invitation'));
        }

        $invitation = new Invitation();
        $invitation->name = $request->name;
        $invitation->owner_name = $request->owner_name;
        $invitation->tag = $request->tag;
        $invitation->location = $request->location;
        $invitation->lattiude = $request->lattiude;
        $invitation->langtiude = $request->langtiude;
        $invitation->lang = $request->lang;
        $invitation->date = $request->date;
        $invitation->time = $request->time;
        if ($request->is_qr_code) {
            $invitation->is_qr_code = $request->is_qr_code;
        }
        if ($request->image_type) {
            $invitation->image_type = $request->image_type;
        }

        if ($request->message_type) {
            $invitation->message_type = $request->message_type;
        }

        if ($request->staues) {
            $invitation->staues = $request->staues;
        }

        if ($request->is_detail) {
            $invitation->is_detail = $request->is_detail;
        }

        $invitation->user_id = $user->id;
        if ($request->count_guests) {
            $invitation->count_guests = $request->count_guests;
        }

        if ($request->file('image')) {
            $image = $request->file('image');
            $image_name = $request->image->hashName();
            $image->move(public_path('invitations'), $image_name);
            $image_path = url('invitations/' . $image_name);
            $invitation->image = $image_path;
            $invitation->image_path = $image_path;
        } else {
            $template = Template::find($request->template_id);
            if ($template) {
                $invitation->image_path = $template->image_path;
                $invitation->template_id = $request->template_id;
            }
        }

        $invitation->save();

        if ($request->file('file')) {
            Excel::import(new GuestsImport($invitation), $request->file('file'));
        }
        $user->count_invitations--;
        $user->save();

        if ($request->users) {
            foreach ($request->users as $guest) {
                $guestRow = new InvitationGuest();

                if (isset($guest['email'])) {
                    $guestRow->email = $guest['email'];
                }
                if (isset($guest['phone'])) {
                    $guestRow->phone = $guest['phone'];
                }
                $guestRow->name = $guest['name'];
                if (isset($guest['nick_name'])) {
                    $guestRow->nick_name = $guest['nick_name'];
                }
                if (isset($guest['count_companions'])) {
                    $guestRow->count_companions = $guest['count_companions'];
                } else {
                    $guestRow->count_companions = 1;
                }
                $guestRow->invitation_id = $invitation->id;
                $guestRow->save();
            }
        }
        $user = Auth::user();
        $user->balance -= InvitationGuest::where('invitation_id', $invitation->id)->count();
        $user->save();

        return redirect()->route('invitations')->with('success', __('api.success'));
    }

    public function Update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required',
            'owner_name' => 'required',
            'location' => 'required',
            'lang' => 'required',
            'image' => 'nullable|max:2048|mimes:png,jpeg,gif,svg,webp,jpg',
            'date' => 'required',
            'time' => 'required',
        ]);

        $user = Auth::user();

        $invitation = Invitation::find($id);
        if (! $invitation) {
            return redirect()->back()->with('error', __('api.not found'));
        }

        $invitation->name = $request->name;
        $invitation->owner_name = $request->owner_name;
        $invitation->tag = $request->tag;
        $invitation->location = $request->location;
        $invitation->lattiude = $request->lat;
        $invitation->langtiude = $request->long;
        $invitation->lang = $request->lang;
        $invitation->date = $request->date;
        $invitation->time = $request->time;
        if ($request->is_qr_code) {
            $invitation->is_qr_code = $request->is_qr_code;
        }
        if ($request->image_type) {
            $invitation->image_type = $request->image_type;
        }

        if ($request->message_type) {
            $invitation->message_type = $request->message_type;
        }

        if ($request->staues) {
            $invitation->staues = $request->staues;
        }

        if ($request->is_detail) {
            $invitation->is_detail = $request->is_detail;
        }

        $invitation->user_id = $user->id;
        if ($request->count_guests) {
            $invitation->count_guests = $request->count_guests;
        }

        if ($request->file('image')) {
            $image_path = uploadImageToR2($request->file('image'), 'invitations');
            $invitation->image = $image_path;
            $invitation->image_path = 'https://pub-7708d9a63bd4488ea45b068466cbd4ce.r2.dev/' . $image_path;
        } elseif ($request->template_id) {
            $template = Template::find($request->template_id);
            if ($template) {
                $invitation->image_path = $template->image_path;
                $invitation->tempalte_id = $template->template_id;
            }
        }

        $invitation->save();
        InvitationGuest::where('invitation_id', $id)->update(['is_updated' => 1]);

        if ($request->file('file')) {
            Excel::import(new GuestsImport($invitation), $request->file('file'));
        }

        if ($request->users) {
            foreach ($request->users as $guest) {
                $guestRow = new InvitationGuest();

                if (isset($guest['email'])) {
                    $guestRow->email = $guest['email'];
                }
                if (isset($guest['phone'])) {
                    $guestRow->phone = $guest['phone'];
                }
                $guestRow->name = $guest['name'];
                if (isset($guest['nick_name'])) {
                    $guestRow->nick_name = $guest['nick_name'];
                }
                if (isset($guest['count_companions'])) {
                    $guestRow->count_companions = $guest['count_companions'];
                } else {
                    $guestRow->count_companions = 1;
                }
                $guestRow->invitation_id = $invitation->id;
                $guestRow->save();

                $this->helper->sendInvitation(
                    $invitation->image,
                    $invitation->name,
                    $invitation->owner_name,
                    $invitation->lang,
                    $invitation->location,
                    $invitation->lattiude,
                    $invitation->langtiude,
                    1,
                    $guestRow->phone
                );
            }
        }
        $user = Auth::user();
        $user->balance -= InvitationGuest::where('invitation_id', $invitation->id)->where('type', 'none')->count();
        $user->save();

        return redirect()->route('invitations')->with('success', __('api.success'));
    }

    public function search(Request $request)
    {
        return view('website.invitations.search')->with(['search' => $request->search]);
    }

    public function confirmInvitation($id)
    {
        $guest = InvitationGuest::find($id);
        if ($guest) {
            $guest->staues = 'accept';
            $guest->save();
        }

        return redirect()->route('invitations.confirm');
    }

    public function apologyInvitation($id)
    {
        $guest = InvitationGuest::find($id);
        if ($guest) {
            $guest->staues = 'reject';
            $guest->save();
        }

        return redirect()->route('invitations.apollogy');
    }

    public function successConfirm()
    {
        return view('website.invitations.success');
    }

    public function apollogy()
    {
        return view('website.invitations.apollogy');
    }
}
