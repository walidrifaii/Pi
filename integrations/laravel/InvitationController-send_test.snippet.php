<?php
/**
 * send_test() — include ALL form fields in the WhatsApp text + image as media
 * =============================================================================
 *
 * 1) WhatsAppNodeCampaignService now forwards every key in each CSV row (except
 *    phone/name used as top-level fields) to Node as template variables {key}.
 *
 * 2) Use those placeholders in $messageTemplate below. Image still goes as
 *    sendCampaign(..., $mediaUrl, 'image') so it appears as the attachment;
 *    {link} can repeat the image URL or a page link.
 *
 * Map form → row keys (match your $request / $invitation fields):
 *   title, sender_name, contact_phone, lang, location, lat, lng, link
 */

// --- After $invitation->save(), replace your $messageTemplate + sendCampaign with: ---

$imagePublicUrl = $invitation->image_path ?: $invitation->image;
if ($imagePublicUrl !== '' && ! preg_match('#^https?://#i', $imagePublicUrl)) {
    $imagePublicUrl = url(ltrim($imagePublicUrl, '/'));
}

$langLabel = $invitation->lang === 'en' ? 'English' : (($invitation->lang === 'ar') ? 'العربية' : (string) $invitation->lang);

if ($invitation->lang === 'en') {
    $messageTemplate =
        "Hello {name},\n\n".
        "This is your Zajel *trial* invitation.\n\n".
        "Occasion: {title}\n".
        "Sender name: {sender_name}\n".
        "Phone: {contact_phone}\n".
        "Invitation language: {lang_label}\n".
        "Location: {location}\n".
        "Coordinates: {lat}, {lng}\n\n".
        "Invitation image / link:\n{link}";
} else {
    $messageTemplate =
        "مرحباً {name}،\n\n".
        "هذه دعوتك التجريبية من زاجل.\n\n".
        "اسم المناسبة: {title}\n".
        "اسم مرسل الدعوة: {sender_name}\n".
        "رقم الهاتف: {contact_phone}\n".
        "لغة الدعوة: {lang_label}\n".
        "موقع المناسبة: {location}\n".
        "الإحداثيات: {lat} ، {lng}\n\n".
        "📩 صورة الدعوة / الرابط:\n{link}";
}

$nodeResult = $this->whatsAppNodeCampaign->sendCampaign(
    'test-invite-' . $invitation->id,
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
        'lat' => (string) ($invitation->lat ?? ''),
        'lng' => (string) ($invitation->long ?? ''),
    ]],
    $imagePublicUrl ?: null,
    $imagePublicUrl ? 'image' : null
);

/*
 * Notes:
 * - {name} is the WhatsApp greeting name (here = user_name). Node always injects it from the row "name" field.
 * - Empty lat/lng will show as blank; you can replace with "—" in Laravel before building the row if you prefer.
 * - APP_URL must be reachable by the Node server if the image is stored on your Laravel host.
 */
