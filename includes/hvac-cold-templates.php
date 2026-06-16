<?php
/**
 * Default builder-compatible cold outreach templates for the HVAC landing page offer.
 */

function seedHVACColdOutreachTemplates() {
    ensureCampaignTemplatesTable();

    $existingRows = dbFetchAll("SELECT name FROM campaign_templates WHERE name LIKE 'HVAC Cold %'");
    $existingNames = [];
    foreach ($existingRows as $row) {
        $existingNames[$row['name']] = true;
    }

    foreach (getHVACColdOutreachTemplates() as $template) {
        if (isset($existingNames[$template['name']])) {
            continue;
        }

        dbInsert(
            "INSERT INTO campaign_templates (name, subject, body_html) VALUES (?, ?, ?)",
            [$template['name'], $template['subject'], $template['body_html']]
        );
    }
}

function getHVACColdOutreachTemplates() {
    $ctaBaseUrl = 'https://abdullahhashmi.com/hvac-growth-expert/';

    $palettes = [
        ['bg' => '#f3f7fb', 'contentBg' => '#ffffff', 'accent' => '#f97316', 'heroBg' => '#fff7ed', 'textColor' => '#102033', 'muted' => '#475569'],
        ['bg' => '#eef6ff', 'contentBg' => '#ffffff', 'accent' => '#0ea5e9', 'heroBg' => '#e0f2fe', 'textColor' => '#0f172a', 'muted' => '#475569'],
        ['bg' => '#f5f3ff', 'contentBg' => '#ffffff', 'accent' => '#7c3aed', 'heroBg' => '#ede9fe', 'textColor' => '#1e1b4b', 'muted' => '#4b5563'],
        ['bg' => '#ecfdf5', 'contentBg' => '#ffffff', 'accent' => '#059669', 'heroBg' => '#d1fae5', 'textColor' => '#064e3b', 'muted' => '#475569'],
        ['bg' => '#fff1f2', 'contentBg' => '#ffffff', 'accent' => '#e11d48', 'heroBg' => '#ffe4e6', 'textColor' => '#111827', 'muted' => '#475569'],
        ['bg' => '#f8fafc', 'contentBg' => '#ffffff', 'accent' => '#2563eb', 'heroBg' => '#dbeafe', 'textColor' => '#111827', 'muted' => '#475569'],
        ['bg' => '#fffbeb', 'contentBg' => '#ffffff', 'accent' => '#d97706', 'heroBg' => '#fef3c7', 'textColor' => '#1f2937', 'muted' => '#4b5563'],
        ['bg' => '#f0fdfa', 'contentBg' => '#ffffff', 'accent' => '#0f766e', 'heroBg' => '#ccfbf1', 'textColor' => '#134e4a', 'muted' => '#475569'],
        ['bg' => '#fdf2f8', 'contentBg' => '#ffffff', 'accent' => '#db2777', 'heroBg' => '#fce7f3', 'textColor' => '#831843', 'muted' => '#4b5563'],
        ['bg' => '#eff6ff', 'contentBg' => '#ffffff', 'accent' => '#1d4ed8', 'heroBg' => '#bfdbfe', 'textColor' => '#172554', 'muted' => '#475569'],
        ['bg' => '#f7fee7', 'contentBg' => '#ffffff', 'accent' => '#65a30d', 'heroBg' => '#ecfccb', 'textColor' => '#365314', 'muted' => '#475569'],
        ['bg' => '#faf5ff', 'contentBg' => '#ffffff', 'accent' => '#9333ea', 'heroBg' => '#f3e8ff', 'textColor' => '#3b0764', 'muted' => '#4b5563'],
    ];

    $angles = [
        ['name' => 'Clicks Leak', 'subject' => 'Your HVAC ad clicks may be leaking before they call', 'title' => 'Your Google Ads may be buying clicks your page is losing', 'subtitle' => 'A focused HVAC landing page can turn more paid traffic into phone calls without raising ad spend.', 'intro' => 'Hi {{first_name}}, if your HVAC Google Ads are sending people to a generic service page, the campaign may look expensive even when the targeting is fine.', 'leftTitle' => 'The leak', 'leftText' => 'Paid visitors land, scan for trust, pricing clues, emergency help, and a call button. If that path is fuzzy, they bounce.', 'rightTitle' => 'The fix', 'rightText' => 'A page built around one HVAC job type, one local promise, and one clear call action can lift ROI from the same clicks.', 'cardTitle' => 'Landing page ROI check', 'cardDescription' => 'I can review the page your ads use and point out the conversion gaps costing calls.', 'cardLabel' => 'Same ad spend. More calls.', 'cta' => 'Review my landing page'],
        ['name' => 'Not The Ads', 'subject' => 'It might not be the Google Ads campaign', 'title' => 'Sometimes the ads are not the problem', 'subtitle' => 'The landing page after the click often decides whether an HVAC lead calls or disappears.', 'intro' => 'Hi {{first_name}}, many HVAC campaigns get blamed for high lead costs when the real problem is the post-click page.', 'leftTitle' => 'Before the click', 'leftText' => 'The ad creates intent and gets the visitor to the page.', 'rightTitle' => 'After the click', 'rightText' => 'The page has to prove trust fast, match the search, and make calling feel easy.', 'cardTitle' => 'Post-click rebuild', 'cardDescription' => 'I build HVAC ad landing pages designed specifically to protect your paid traffic and improve conversion.', 'cardLabel' => 'Built for Google Ads traffic', 'cta' => 'See the HVAC page system'],
        ['name' => 'Call First Page', 'subject' => 'A better page for HVAC calls', 'title' => 'Your HVAC landing page should be built for calls first', 'subtitle' => 'Clicks are only useful when the page makes the next step obvious.', 'intro' => 'Hi {{first_name}}, HVAC buyers do not want to dig through a full website when their AC or furnace needs help.', 'leftTitle' => 'What they need', 'leftText' => 'Fast proof you serve their area, handle their issue, and can be reached now.', 'rightTitle' => 'What your page needs', 'rightText' => 'A call-first layout with trust cues, service-area relevance, and fewer distractions.', 'cardTitle' => 'Call-focused HVAC layout', 'cardDescription' => 'A sharper page can help convert existing Google Ads traffic into booked calls.', 'cardLabel' => 'Designed around the phone call', 'cta' => 'Get a page review'],
        ['name' => 'Cost Per Lead', 'subject' => 'One reason HVAC cost per lead climbs', 'title' => 'High HVAC cost per lead can start on the landing page', 'subtitle' => 'A weak page makes every Google Ads click more expensive than it should be.', 'intro' => 'Hi {{first_name}}, when the landing page underperforms, cost per lead rises even if the campaign is sending qualified local traffic.', 'leftTitle' => 'Expensive symptom', 'leftText' => 'Good clicks do not become calls often enough.', 'rightTitle' => 'Practical improvement', 'rightText' => 'Rebuild the page around search intent, proof, urgency, and one conversion path.', 'cardTitle' => 'Lower waste, not bigger budget', 'cardDescription' => 'The goal is to get more value from the traffic you already buy.', 'cardLabel' => 'ROI starts after the click', 'cta' => 'Check my current page'],
        ['name' => 'Emergency HVAC', 'subject' => 'Emergency HVAC clicks need a faster page', 'title' => 'Emergency HVAC traffic has almost no patience', 'subtitle' => 'If the page does not answer fast, the next contractor is one tap away.', 'intro' => 'Hi {{first_name}}, emergency AC and furnace searches are high-intent but unforgiving. The landing page has to move fast.', 'leftTitle' => 'Visitor mindset', 'leftText' => 'They want availability, location fit, trust, and a tap-to-call action.', 'rightTitle' => 'Page mindset', 'rightText' => 'Remove friction, show the right proof, and keep the phone CTA visible.', 'cardTitle' => 'Emergency-ready landing page', 'cardDescription' => 'Built to help urgent Google Ads clicks become actual inbound calls.', 'cardLabel' => 'Speed + trust + call action', 'cta' => 'View the page approach'],
        ['name' => 'Service Area Match', 'subject' => 'Does your HVAC page match the city they searched?', 'title' => 'Local HVAC clicks convert better when the page feels local', 'subtitle' => 'A generic landing page can weaken trust after a local Google Ads click.', 'intro' => 'Hi {{first_name}}, if someone searches HVAC help in their city, the page should immediately feel relevant to that city and service.', 'leftTitle' => 'Generic page', 'leftText' => 'Broad copy, unclear coverage, and no local reason to trust the company.', 'rightTitle' => 'Local page', 'rightText' => 'City/service match, proof, reviews, and a direct call path for that search intent.', 'cardTitle' => 'Local-intent landing page', 'cardDescription' => 'I build HVAC pages that align paid clicks with the visitor location and service need.', 'cardLabel' => 'More relevance after the click', 'cta' => 'Audit my ad page'],
        ['name' => 'Website Vs Landing Page', 'subject' => 'Your website is not always the best ad destination', 'title' => 'Your HVAC website and your ad landing page have different jobs', 'subtitle' => 'A website informs. A Google Ads landing page must convert fast.', 'intro' => 'Hi {{first_name}}, many HVAC companies send paid clicks to a normal website page. That page may look fine but still waste ad traffic.', 'leftTitle' => 'Website job', 'leftText' => 'Explain the company, services, history, and navigation.', 'rightTitle' => 'Landing page job', 'rightText' => 'Match one search intent and turn that visitor into a call or quote request.', 'cardTitle' => 'Dedicated HVAC ad page', 'cardDescription' => 'A focused landing page can improve ROI without touching your entire website.', 'cardLabel' => 'Built for conversion', 'cta' => 'See how it works'],
        ['name' => 'Above The Fold', 'subject' => 'The first screen of your HVAC page matters', 'title' => 'Most HVAC ad clicks decide in the first few seconds', 'subtitle' => 'The first screen has to show the offer, trust, area, and call action immediately.', 'intro' => 'Hi {{first_name}}, if your page opens with vague copy or buried contact buttons, paid visitors may leave before seeing your best proof.', 'leftTitle' => 'Weak first screen', 'leftText' => 'Generic headline, hidden phone number, and no clear service-area reassurance.', 'rightTitle' => 'Strong first screen', 'rightText' => 'Specific HVAC promise, local proof, tap-to-call button, and clear next step.', 'cardTitle' => 'First-screen conversion audit', 'cardDescription' => 'I can show what your ad visitors see first and where the call path gets weak.', 'cardLabel' => 'First seconds matter', 'cta' => 'Review my first screen'],
        ['name' => 'Trust Stack', 'subject' => 'HVAC clicks need trust before they call', 'title' => 'Trust is the bridge between a click and a call', 'subtitle' => 'A landing page should answer why this HVAC company is safe to contact now.', 'intro' => 'Hi {{first_name}}, paid HVAC visitors often compare quickly. They need proof before they commit to calling.', 'leftTitle' => 'Missing proof', 'leftText' => 'No reviews near the CTA, weak credentials, and unclear service process.', 'rightTitle' => 'Trust stack', 'rightText' => 'Reviews, guarantees, service-area proof, fast response language, and simple next step.', 'cardTitle' => 'Trust-led HVAC page', 'cardDescription' => 'I structure HVAC landing pages so proof appears exactly where hesitation happens.', 'cardLabel' => 'Reduce hesitation after the click', 'cta' => 'Get a trust audit'],
        ['name' => 'Mobile Calls', 'subject' => 'Most HVAC ad clicks are mobile', 'title' => 'Mobile HVAC visitors should not have to hunt for the call button', 'subtitle' => 'If the mobile landing page is clunky, paid traffic turns into silent exits.', 'intro' => 'Hi {{first_name}}, Google Ads traffic for HVAC is often mobile and urgent. That means the page has to feel fast and thumb-friendly.', 'leftTitle' => 'Mobile friction', 'leftText' => 'Slow load, tiny buttons, too much text, and forms before trust is built.', 'rightTitle' => 'Mobile conversion', 'rightText' => 'Tap-to-call placement, concise sections, fast proof, and a clear service promise.', 'cardTitle' => 'Mobile-first HVAC landing page', 'cardDescription' => 'Designed for the way homeowners actually respond to HVAC ads.', 'cardLabel' => 'Tap, trust, call', 'cta' => 'Check my mobile page'],
        ['name' => 'Same Budget More Calls', 'subject' => 'More HVAC calls from the same ad budget', 'title' => 'The fastest ROI lift may be after the click', 'subtitle' => 'Better landing pages can help the same Google Ads spend produce more inquiries.', 'intro' => 'Hi {{first_name}}, if you are already paying for HVAC clicks, improving the page can be a cleaner lever than simply increasing budget.', 'leftTitle' => 'More spend', 'leftText' => 'Can buy more clicks, but it also scales the waste if the page is weak.', 'rightTitle' => 'Better page', 'rightText' => 'Improves how much value you get from clicks you already paid for.', 'cardTitle' => 'Conversion-focused rebuild', 'cardDescription' => 'I help HVAC companies turn paid traffic into more real calls with purpose-built landing pages.', 'cardLabel' => 'Improve the traffic you already have', 'cta' => 'Show me the system'],
        ['name' => 'Quote Form Friction', 'subject' => 'Is your HVAC form costing calls?', 'title' => 'Too much form friction can hurt HVAC ad ROI', 'subtitle' => 'Some homeowners want to call now, not complete a long quote request.', 'intro' => 'Hi {{first_name}}, if paid visitors are forced through a slow form too early, you may lose ready-to-call homeowners.', 'leftTitle' => 'Friction point', 'leftText' => 'Long fields, unclear response time, and no simple phone path.', 'rightTitle' => 'Better path', 'rightText' => 'Give call-first visitors a fast option while still capturing form leads from slower buyers.', 'cardTitle' => 'Balanced call + form page', 'cardDescription' => 'The page should support both urgent calls and quote requests without confusing either visitor.', 'cardLabel' => 'Less friction after the ad click', 'cta' => 'Review my form flow'],
        ['name' => 'Ad Message Match', 'subject' => 'Your HVAC ad and page should say the same thing', 'title' => 'Message match can make or break HVAC landing page ROI', 'subtitle' => 'The visitor clicked for one reason. The landing page should continue that exact promise.', 'intro' => 'Hi {{first_name}}, when the ad talks about AC repair but the page feels like a general HVAC homepage, conversion usually suffers.', 'leftTitle' => 'Mismatch', 'leftText' => 'The ad promises one thing, the page spreads attention across everything.', 'rightTitle' => 'Match', 'rightText' => 'The page repeats the service intent, location fit, and next step immediately.', 'cardTitle' => 'Ad-to-page alignment', 'cardDescription' => 'I build HVAC landing pages that keep the promise made in the Google Ad.', 'cardLabel' => 'One click, one message', 'cta' => 'Check message match'],
        ['name' => 'Seasonal Demand', 'subject' => 'Peak HVAC season is rough on weak landing pages', 'title' => 'Peak season clicks deserve a page built for urgency', 'subtitle' => 'When demand rises, every wasted click costs more.', 'intro' => 'Hi {{first_name}}, during hot or cold spikes, HVAC clicks get competitive. A weak page can turn demand into missed calls.', 'leftTitle' => 'Busy season risk', 'leftText' => 'Higher click costs, more competitors, and impatient homeowners.', 'rightTitle' => 'Prepared page', 'rightText' => 'Urgency, availability, trust, and fast phone CTA built into the landing page.', 'cardTitle' => 'Season-ready landing page', 'cardDescription' => 'A page built before demand spikes can help protect your ad ROI.', 'cardLabel' => 'Ready before the rush', 'cta' => 'Prepare my ad page'],
        ['name' => 'Competitor Comparison', 'subject' => 'The next HVAC company is one back button away', 'title' => 'Your landing page is competing before your team ever speaks', 'subtitle' => 'Google Ads visitors compare pages quickly, not just prices.', 'intro' => 'Hi {{first_name}}, after someone clicks your HVAC ad, they can still choose any competitor in seconds.', 'leftTitle' => 'What loses them', 'leftText' => 'Slow proof, unclear difference, buried reviews, or a page that feels generic.', 'rightTitle' => 'What keeps them', 'rightText' => 'Specific service promise, local trust, obvious phone CTA, and quick confidence.', 'cardTitle' => 'Competitive landing page review', 'cardDescription' => 'I can review how your page stacks up against what a paid HVAC visitor expects.', 'cardLabel' => 'Win the post-click moment', 'cta' => 'Review my page'],
        ['name' => 'Lead Quality', 'subject' => 'Better HVAC pages can improve lead quality too', 'title' => 'A focused page can filter and convert better HVAC leads', 'subtitle' => 'Good landing pages do more than get clicks. They shape the inquiry.', 'intro' => 'Hi {{first_name}}, if your page is vague, visitors may not understand the exact service, area, or next step before contacting you.', 'leftTitle' => 'Vague page', 'leftText' => 'Attracts mixed inquiries and leaves homeowners unsure what happens next.', 'rightTitle' => 'Clear page', 'rightText' => 'Frames the service, area, urgency, and call expectation before the lead arrives.', 'cardTitle' => 'Higher-intent page structure', 'cardDescription' => 'I build HVAC landing pages around the jobs you actually want to book.', 'cardLabel' => 'Clarity improves conversion', 'cta' => 'Get a clarity review'],
        ['name' => 'Speed Problem', 'subject' => 'Slow HVAC pages quietly waste Google Ads money', 'title' => 'A slow landing page can tax every HVAC click', 'subtitle' => 'Paid traffic should not wait on a heavy page before deciding to call.', 'intro' => 'Hi {{first_name}}, if your HVAC landing page loads slowly, some paid visitors are gone before the page sells anything.', 'leftTitle' => 'Speed drag', 'leftText' => 'Slow load, heavy sections, and mobile friction weaken campaign ROI.', 'rightTitle' => 'Fast path', 'rightText' => 'Lean sections, direct proof, and a visible call CTA can protect urgent traffic.', 'cardTitle' => 'Fast HVAC landing page', 'cardDescription' => 'Designed to keep paid visitors moving toward a call instead of waiting around.', 'cardLabel' => 'Speed supports ROI', 'cta' => 'Check my landing page speed'],
        ['name' => 'Phone CTA', 'subject' => 'Where is the phone call on your HVAC ad page?', 'title' => 'If the call CTA is not obvious, HVAC ad traffic cools off', 'subtitle' => 'The page should make calling feel like the natural next step.', 'intro' => 'Hi {{first_name}}, HVAC visitors from Google Ads are often ready to act. A hidden phone number or weak CTA can create unnecessary drop-off.', 'leftTitle' => 'CTA problem', 'leftText' => 'Phone number buried in the header, button language that feels vague, or too many competing actions.', 'rightTitle' => 'CTA system', 'rightText' => 'Persistent call path, clear button copy, trust near the CTA, and service-specific urgency.', 'cardTitle' => 'Call CTA optimization', 'cardDescription' => 'I rebuild HVAC landing pages so the phone call is easy, trusted, and trackable.', 'cardLabel' => 'Make the call path obvious', 'cta' => 'Audit my CTA'],
        ['name' => 'Review Placement', 'subject' => 'Reviews should support the HVAC call button', 'title' => 'Reviews work harder when they sit near the decision point', 'subtitle' => 'Trust proof should not be hidden below everything important.', 'intro' => 'Hi {{first_name}}, if your best reviews are buried low on the page, paid visitors may never see them before deciding whether to call.', 'leftTitle' => 'Weak placement', 'leftText' => 'Reviews separated from the CTA or shown after too much scrolling.', 'rightTitle' => 'Strong placement', 'rightText' => 'Local proof appears close to the headline, service promise, and call action.', 'cardTitle' => 'Review-backed landing page', 'cardDescription' => 'I place proof where it reduces hesitation and helps turn clicks into calls.', 'cardLabel' => 'Trust near the CTA', 'cta' => 'Review my proof placement'],
        ['name' => 'Landing Page Audit', 'subject' => 'Want a quick HVAC landing page audit?', 'title' => 'I can spot the landing page gaps hurting your HVAC ad ROI', 'subtitle' => 'A short audit can reveal why clicks are not turning into enough calls.', 'intro' => 'Hi {{first_name}}, I help HVAC companies improve Google Ads ROI by fixing the landing page after the click.', 'leftTitle' => 'What I check', 'leftText' => 'Message match, mobile layout, trust proof, CTA strength, speed, and call path.', 'rightTitle' => 'What you get', 'rightText' => 'A clear list of changes that can make the page more conversion-focused.', 'cardTitle' => 'Quick page audit', 'cardDescription' => 'No campaign rebuild required. This is about the page your paid traffic lands on.', 'cardLabel' => 'Find the leaks first', 'cta' => 'Request a quick audit'],
        ['name' => 'No Campaign Rebuild', 'subject' => 'No need to rebuild the whole ad campaign first', 'title' => 'Fixing the landing page can be the cleaner first move', 'subtitle' => 'Before changing bids or keywords, make sure the destination page can convert.', 'intro' => 'Hi {{first_name}}, if your HVAC ads already bring visitors, the page may be the simplest place to improve ROI.', 'leftTitle' => 'Campaign work', 'leftText' => 'Often takes testing, budget movement, and more time to judge.', 'rightTitle' => 'Page work', 'rightText' => 'Can immediately improve the path from click to phone call.', 'cardTitle' => 'Page-first ROI improvement', 'cardDescription' => 'I focus on the landing page experience, not replacing your ad manager.', 'cardLabel' => 'Improve the destination', 'cta' => 'See the page-first approach'],
        ['name' => 'Booked Jobs', 'subject' => 'Clicks are not the goal. Booked HVAC jobs are.', 'title' => 'Your landing page should move clicks closer to booked jobs', 'subtitle' => 'A page built for real calls can make paid traffic feel less like a guessing game.', 'intro' => 'Hi {{first_name}}, Google Ads clicks only matter if enough of them become conversations with homeowners who need HVAC help.', 'leftTitle' => 'Wrong metric', 'leftText' => 'Traffic and impressions can look active while calls stay flat.', 'rightTitle' => 'Right metric', 'rightText' => 'The page should be designed around calls, quote requests, and booked service opportunities.', 'cardTitle' => 'Booked-call landing page', 'cardDescription' => 'I build pages that connect paid search intent to a clear HVAC service conversation.', 'cardLabel' => 'From clicks to calls', 'cta' => 'Review my call path'],
        ['name' => 'Install Jobs', 'subject' => 'A better page for HVAC install leads', 'title' => 'Install traffic needs a different landing page than repair traffic', 'subtitle' => 'High-value HVAC jobs deserve a page that matches buyer intent.', 'intro' => 'Hi {{first_name}}, if your Google Ads are targeting HVAC installs, the landing page should not feel like a generic repair page.', 'leftTitle' => 'Install buyer', 'leftText' => 'Needs trust, financing cues, process clarity, and confidence before requesting an estimate.', 'rightTitle' => 'Install page', 'rightText' => 'Frames the value, reduces risk, and guides the visitor to a quote request or call.', 'cardTitle' => 'Install-focused landing page', 'cardDescription' => 'Built to help paid install traffic become higher-value conversations.', 'cardLabel' => 'Match the job value', 'cta' => 'Review my install page'],
        ['name' => 'Repair Jobs', 'subject' => 'A sharper page for HVAC repair clicks', 'title' => 'Repair clicks need urgency, trust, and a fast call path', 'subtitle' => 'Repair visitors are often ready to act if the page gives them confidence.', 'intro' => 'Hi {{first_name}}, HVAC repair traffic from Google Ads usually has immediate intent. The landing page should not slow that down.', 'leftTitle' => 'Repair visitor', 'leftText' => 'They want availability, fast response, clear area coverage, and proof you can help.', 'rightTitle' => 'Repair page', 'rightText' => 'Lead with the specific repair promise and make calling the easiest option.', 'cardTitle' => 'Repair landing page rebuild', 'cardDescription' => 'I build pages that support urgent HVAC repair intent from paid search.', 'cardLabel' => 'Built for urgent calls', 'cta' => 'Review my repair page'],
        ['name' => 'Form And Call Tracking', 'subject' => 'Can you tell which HVAC page clicks become calls?', 'title' => 'Your landing page should make ROI easier to see', 'subtitle' => 'A cleaner call and form path supports better tracking after the click.', 'intro' => 'Hi {{first_name}}, if the page has scattered buttons and unclear actions, it becomes harder to understand what paid traffic is really doing.', 'leftTitle' => 'Messy actions', 'leftText' => 'Multiple CTAs, mixed destinations, and weak event clarity.', 'rightTitle' => 'Cleaner actions', 'rightText' => 'One main call path, one quote path, and page sections that support measurement.', 'cardTitle' => 'Trackable landing page flow', 'cardDescription' => 'I design HVAC pages with clearer conversion actions for Google Ads traffic.', 'cardLabel' => 'Cleaner ROI signals', 'cta' => 'Check my conversion flow'],
        ['name' => 'Homepage Traffic', 'subject' => 'Are you sending HVAC ad traffic to the homepage?', 'title' => 'A homepage usually asks paid visitors to do too much work', 'subtitle' => 'Google Ads traffic needs a direct path, not a menu of options.', 'intro' => 'Hi {{first_name}}, if your HVAC ads land on the homepage, visitors may have to search for the service they already clicked for.', 'leftTitle' => 'Homepage issue', 'leftText' => 'Navigation, broad messaging, and distractions dilute the paid click.', 'rightTitle' => 'Landing page path', 'rightText' => 'One service, one area promise, one call action, and proof built around that intent.', 'cardTitle' => 'Homepage-to-landing-page upgrade', 'cardDescription' => 'I create dedicated HVAC ad pages so paid clicks do not get lost.', 'cardLabel' => 'Focus the destination', 'cta' => 'Replace my ad destination'],
        ['name' => 'Campaign ROI Rescue', 'subject' => 'Before you pause the HVAC campaign', 'title' => 'Before pausing Google Ads, check the landing page', 'subtitle' => 'The page may be the reason qualified clicks are not becoming enough calls.', 'intro' => 'Hi {{first_name}}, I have seen HVAC ad campaigns look weak because the landing page failed to convert the traffic they were already getting.', 'leftTitle' => 'Pause risk', 'leftText' => 'You may stop a campaign that was sending useful visitors.', 'rightTitle' => 'Page check', 'rightText' => 'Find whether the page is blocking calls before judging the whole campaign.', 'cardTitle' => 'Campaign ROI rescue page', 'cardDescription' => 'A conversion-focused landing page can give existing traffic a fair chance to perform.', 'cardLabel' => 'Diagnose before pausing', 'cta' => 'Diagnose my page'],
        ['name' => 'One Service One Page', 'subject' => 'One HVAC service, one landing page', 'title' => 'Focused HVAC landing pages convert clearer intent', 'subtitle' => 'AC repair, furnace repair, and installs should not all fight for the same paid click.', 'intro' => 'Hi {{first_name}}, if one page tries to cover every HVAC service, it may not match the specific intent behind each Google Ads click.', 'leftTitle' => 'Broad page', 'leftText' => 'Covers everything but sells nothing with enough precision.', 'rightTitle' => 'Focused page', 'rightText' => 'Matches the exact service, proof, and call action the visitor expected.', 'cardTitle' => 'Service-specific page system', 'cardDescription' => 'I build HVAC pages around the service category your ads are promoting.', 'cardLabel' => 'Specific beats generic', 'cta' => 'Review my service page'],
        ['name' => 'Furnace Traffic', 'subject' => 'Furnace clicks need winter-ready pages', 'title' => 'Furnace traffic should land on a page built for cold-weather urgency', 'subtitle' => 'Seasonal intent converts better when the page reflects the visitor problem.', 'intro' => 'Hi {{first_name}}, furnace repair visitors need quick confidence that help is available in their area.', 'leftTitle' => 'Cold-weather intent', 'leftText' => 'Urgency, safety, comfort, and response time matter immediately.', 'rightTitle' => 'Landing page fit', 'rightText' => 'Lead with furnace-specific messaging, trust proof, and a simple call action.', 'cardTitle' => 'Furnace ad landing page', 'cardDescription' => 'A service-specific page can help winter Google Ads traffic convert into calls.', 'cardLabel' => 'Seasonal page relevance', 'cta' => 'Review my furnace page'],
        ['name' => 'AC Traffic', 'subject' => 'AC repair clicks need summer-speed pages', 'title' => 'AC repair traffic needs a page that feels immediate', 'subtitle' => 'When the house is hot, visitors want proof and a phone button fast.', 'intro' => 'Hi {{first_name}}, AC repair Google Ads clicks are often high intent. The landing page should make action effortless.', 'leftTitle' => 'Summer intent', 'leftText' => 'Fast help, local coverage, visible phone number, and trust cues.', 'rightTitle' => 'Page response', 'rightText' => 'A focused AC repair layout that keeps the visitor moving toward a call.', 'cardTitle' => 'AC repair landing page', 'cardDescription' => 'Built to help hot-weather paid traffic become service calls.', 'cardLabel' => 'Fast path to a call', 'cta' => 'Review my AC page'],
        ['name' => 'Commercial HVAC', 'subject' => 'Commercial HVAC clicks need different proof', 'title' => 'Commercial HVAC landing pages need buyer-specific confidence', 'subtitle' => 'Business buyers often need capability, response process, and trust before reaching out.', 'intro' => 'Hi {{first_name}}, if your paid traffic includes commercial HVAC searches, the landing page should speak to that buyer directly.', 'leftTitle' => 'Residential proof', 'leftText' => 'Can feel too light for commercial service or maintenance buyers.', 'rightTitle' => 'Commercial proof', 'rightText' => 'Show response process, service capability, reliability, and clear contact path.', 'cardTitle' => 'Commercial HVAC landing page', 'cardDescription' => 'A better page can match the seriousness of commercial paid-search intent.', 'cardLabel' => 'Proof for business buyers', 'cta' => 'Review my commercial page'],
        ['name' => 'Maintenance Plans', 'subject' => 'A page for HVAC maintenance plan traffic', 'title' => 'Maintenance plan clicks need value clarity fast', 'subtitle' => 'Recurring HVAC offers need a page that makes the benefit simple and trusted.', 'intro' => 'Hi {{first_name}}, if you promote maintenance plans with Google Ads, the landing page has to explain value without overwhelming visitors.', 'leftTitle' => 'Offer confusion', 'leftText' => 'Too many plan details before the visitor understands why it matters.', 'rightTitle' => 'Value clarity', 'rightText' => 'Simple benefits, trust proof, seasonal timing, and an easy request path.', 'cardTitle' => 'Maintenance plan landing page', 'cardDescription' => 'I build HVAC pages that make recurring service offers easier to understand and act on.', 'cardLabel' => 'Clear value. Clear action.', 'cta' => 'Review my plan page'],
        ['name' => 'Financing Page', 'subject' => 'Financing can help HVAC install pages convert', 'title' => 'HVAC install pages should reduce sticker shock', 'subtitle' => 'Financing and trust cues can support high-value Google Ads traffic.', 'intro' => 'Hi {{first_name}}, homeowners considering HVAC replacement may hesitate if the page does not lower perceived risk.', 'leftTitle' => 'Hesitation', 'leftText' => 'Price anxiety, unclear process, and uncertainty about who to trust.', 'rightTitle' => 'Conversion support', 'rightText' => 'Financing cues, estimate process, reviews, and a low-friction CTA.', 'cardTitle' => 'Install ROI landing page', 'cardDescription' => 'A page built to support higher-ticket HVAC decisions from paid traffic.', 'cardLabel' => 'Reduce friction for install leads', 'cta' => 'Review my install flow'],
        ['name' => 'Owner Direct', 'subject' => 'Small landing page idea for your HVAC ads', 'title' => 'A small page change can make a big difference to paid HVAC traffic', 'subtitle' => 'If the current page is not built for Google Ads, it may be quietly lowering ROI.', 'intro' => 'Hi {{first_name}}, quick note: I help HVAC businesses improve landing pages used for Google Ads so more paid clicks turn into calls.', 'leftTitle' => 'Simple issue', 'leftText' => 'The ad gets the click, but the page does not make calling obvious enough.', 'rightTitle' => 'Simple goal', 'rightText' => 'Make the page clearer, faster, more trusted, and more call-focused.', 'cardTitle' => 'Owner-friendly page review', 'cardDescription' => 'I can send a direct review of what I would change on your current page.', 'cardLabel' => 'No jargon, just page fixes', 'cta' => 'Send me the review'],
        ['name' => 'Local Proof', 'subject' => 'Local proof can lift HVAC landing page trust', 'title' => 'Local trust should show up before the visitor hesitates', 'subtitle' => 'HVAC paid traffic converts better when homeowners see proof that feels nearby and relevant.', 'intro' => 'Hi {{first_name}}, if your Google Ads page uses generic proof, local visitors may not feel enough confidence to call.', 'leftTitle' => 'Generic proof', 'leftText' => 'Broad claims, no local cues, and reviews that feel detached from the service area.', 'rightTitle' => 'Local proof', 'rightText' => 'Area language, nearby reviews, service-specific reassurance, and direct phone CTA.', 'cardTitle' => 'Local-proof landing page', 'cardDescription' => 'I build HVAC pages that make the company feel relevant to the searcher location.', 'cardLabel' => 'Trust that feels close', 'cta' => 'Review my local proof'],
        ['name' => 'Guarantee Angle', 'subject' => 'Does your HVAC page reduce buyer risk?', 'title' => 'Risk reversal can help HVAC visitors take the next step', 'subtitle' => 'The page should answer the quiet question: why should I trust this company?', 'intro' => 'Hi {{first_name}}, landing pages often miss the trust and guarantee language that helps paid visitors feel safe enough to call.', 'leftTitle' => 'Visitor concern', 'leftText' => 'Will they show up, be honest, serve my area, and solve the issue?', 'rightTitle' => 'Page answer', 'rightText' => 'Guarantees, reviews, credentials, and clear next-step expectations near the CTA.', 'cardTitle' => 'Risk-reducing HVAC page', 'cardDescription' => 'I structure the page to reduce hesitation before the call button.', 'cardLabel' => 'Confidence before contact', 'cta' => 'Review my trust cues'],
        ['name' => 'Headline Fix', 'subject' => 'Your HVAC landing page headline might be too vague', 'title' => 'The headline has to match the paid search intent immediately', 'subtitle' => 'A vague headline can make even qualified clicks question if they are in the right place.', 'intro' => 'Hi {{first_name}}, if your HVAC landing page starts with a broad brand headline, paid visitors may not instantly see their problem.', 'leftTitle' => 'Vague headline', 'leftText' => 'Talks about the company but not the visitor service need.', 'rightTitle' => 'Conversion headline', 'rightText' => 'Names the HVAC service, area, and call outcome in plain language.', 'cardTitle' => 'Headline and hero audit', 'cardDescription' => 'I can review whether your first section is helping or hurting Google Ads ROI.', 'cardLabel' => 'Clarity starts at the top', 'cta' => 'Audit my headline'],
        ['name' => 'CTA Copy', 'subject' => 'The words on your HVAC CTA matter', 'title' => 'Button copy can change how HVAC visitors respond', 'subtitle' => 'Generic CTAs can feel weak when a homeowner needs fast service.', 'intro' => 'Hi {{first_name}}, a button that says Learn More may not match the urgency of an HVAC repair search.', 'leftTitle' => 'Weak CTA', 'leftText' => 'Vague action words that do not tell the visitor what happens next.', 'rightTitle' => 'Stronger CTA', 'rightText' => 'Call-focused, service-specific wording supported by trust and response expectations.', 'cardTitle' => 'CTA copy review', 'cardDescription' => 'I can suggest stronger call and quote CTAs for your Google Ads landing page.', 'cardLabel' => 'Make the next step clear', 'cta' => 'Review my CTA copy'],
        ['name' => 'Before After', 'subject' => 'Before and after: HVAC landing page ROI', 'title' => 'Before: paid clicks. After: a page built to turn them into calls', 'subtitle' => 'The difference is not decoration. It is conversion structure.', 'intro' => 'Hi {{first_name}}, a good HVAC landing page guides the visitor from problem to trust to action without making them think too hard.', 'leftTitle' => 'Before', 'leftText' => 'Generic website page, scattered CTAs, low local relevance, and slow proof.', 'rightTitle' => 'After', 'rightText' => 'Service-specific headline, call-first layout, local trust, and clear conversion tracking.', 'cardTitle' => 'HVAC landing page rebuild', 'cardDescription' => 'I create pages designed for Google Ads ROI, not just a nicer look.', 'cardLabel' => 'Structure beats decoration', 'cta' => 'See the rebuild approach'],
        ['name' => 'Lead Magnet Audit', 'subject' => 'Can I send a 3-point HVAC page teardown?', 'title' => 'I can send a quick teardown of your current HVAC ad page', 'subtitle' => 'Three clear fixes: message, trust, and call path.', 'intro' => 'Hi {{first_name}}, I help HVAC companies improve the landing pages connected to Google Ads. A quick teardown usually shows the obvious leaks fast.', 'leftTitle' => 'Point 1', 'leftText' => 'Does the page match the search intent from the ad?', 'rightTitle' => 'Point 2', 'rightText' => 'Does the page make trust and calling easy on mobile?', 'cardTitle' => '3-point teardown', 'cardDescription' => 'I will look at message match, trust placement, and CTA clarity on your page.', 'cardLabel' => 'Fast, specific, useful', 'cta' => 'Send me the teardown'],
        ['name' => 'No More Pretty Only', 'subject' => 'Pretty HVAC pages still have to convert', 'title' => 'A pretty page can still waste HVAC Google Ads traffic', 'subtitle' => 'Design matters, but conversion structure matters more.', 'intro' => 'Hi {{first_name}}, some HVAC landing pages look clean but still fail to move paid visitors toward a call.', 'leftTitle' => 'Pretty only', 'leftText' => 'Nice visuals, broad copy, weak proof sequence, and unclear next step.', 'rightTitle' => 'Conversion design', 'rightText' => 'Every section has a job: reassure, clarify, or push the visitor closer to contact.', 'cardTitle' => 'ROI-focused page design', 'cardDescription' => 'I build HVAC landing pages that look sharp and support paid-search conversion.', 'cardLabel' => 'Looks good. Works harder.', 'cta' => 'Review my page design'],
        ['name' => 'Call Tracking Friendly', 'subject' => 'Your HVAC landing page should support call tracking', 'title' => 'A better page makes HVAC call tracking cleaner', 'subtitle' => 'Clear actions make it easier to see whether paid traffic is producing real calls.', 'intro' => 'Hi {{first_name}}, when a landing page has too many paths, tracking the true value of Google Ads traffic gets messy.', 'leftTitle' => 'Tracking clutter', 'leftText' => 'Multiple phone placements, unrelated links, and unclear CTA hierarchy.', 'rightTitle' => 'Tracking clarity', 'rightText' => 'Dedicated calls and quote actions tied to the purpose of the landing page.', 'cardTitle' => 'Tracking-friendly page layout', 'cardDescription' => 'I design HVAC landing pages with clean conversion actions your reporting can understand.', 'cardLabel' => 'Cleaner calls from paid traffic', 'cta' => 'Audit my tracking path'],
        ['name' => 'High Intent Keywords', 'subject' => 'High-intent HVAC keywords need high-intent pages', 'title' => 'Do not send expensive HVAC keywords to a low-intent page', 'subtitle' => 'The more valuable the click, the more focused the page should be.', 'intro' => 'Hi {{first_name}}, HVAC keywords like repair, replacement, and emergency service are too expensive to send to a generic page.', 'leftTitle' => 'High-intent click', 'leftText' => 'The visitor already has a specific problem and expects a specific answer.', 'rightTitle' => 'High-intent page', 'rightText' => 'The page mirrors that problem, builds trust quickly, and makes contact easy.', 'cardTitle' => 'Keyword-matched landing page', 'cardDescription' => 'I build HVAC pages that match the intent your Google Ads are paying for.', 'cardLabel' => 'Protect expensive clicks', 'cta' => 'Review my keyword page'],
        ['name' => 'Landing Page Scorecard', 'subject' => 'A quick scorecard for your HVAC ad page', 'title' => 'How would your HVAC landing page score after the click?', 'subtitle' => 'Message, trust, mobile, speed, and CTA clarity all affect ROI.', 'intro' => 'Hi {{first_name}}, I use a simple scorecard to find where an HVAC landing page is wasting paid traffic.', 'leftTitle' => 'Score areas', 'leftText' => 'Search match, local proof, first screen, mobile flow, and call visibility.', 'rightTitle' => 'Outcome', 'rightText' => 'A prioritized list of page improvements that support more calls from the same traffic.', 'cardTitle' => 'HVAC ad page scorecard', 'cardDescription' => 'I can score your current page and send the main fixes I would make.', 'cardLabel' => 'Know the weak spots', 'cta' => 'Score my page'],
        ['name' => 'Trust Before Form', 'subject' => 'Ask for the HVAC lead after trust is built', 'title' => 'Forms convert better when the page earns trust first', 'subtitle' => 'Paid visitors need a reason to share details or call.', 'intro' => 'Hi {{first_name}}, if the landing page asks for contact details before building trust, HVAC visitors may hesitate.', 'leftTitle' => 'Too early', 'leftText' => 'A form appears before proof, service clarity, or response expectations.', 'rightTitle' => 'Better sequence', 'rightText' => 'Service promise, trust proof, local relevance, then a simple call or quote CTA.', 'cardTitle' => 'Trust-first page sequence', 'cardDescription' => 'I build HVAC landing pages that earn the lead before asking for it.', 'cardLabel' => 'Better order, better response', 'cta' => 'Review my lead flow'],
        ['name' => 'Retain Existing Ads', 'subject' => 'Keep your HVAC ads. Improve where they land.', 'title' => 'You may not need new ads. You may need a better destination.', 'subtitle' => 'If paid traffic is already coming in, the landing page can be the highest-leverage fix.', 'intro' => 'Hi {{first_name}}, I am not reaching out to replace your Google Ads. I build the landing pages that help those clicks convert better.', 'leftTitle' => 'Keep the traffic', 'leftText' => 'Your campaign can continue sending visitors from proven keywords.', 'rightTitle' => 'Improve the page', 'rightText' => 'Give those visitors a clearer, faster, more trusted path to call.', 'cardTitle' => 'Landing page support for existing ads', 'cardDescription' => 'A focused page can help your current ad traffic perform harder.', 'cardLabel' => 'Same campaign, stronger destination', 'cta' => 'Improve my destination page'],
        ['name' => 'Two Minute Review', 'subject' => '2-minute idea for your HVAC ad landing page', 'title' => 'I can usually find one landing page leak in two minutes', 'subtitle' => 'The first screen, CTA path, and trust placement reveal a lot.', 'intro' => 'Hi {{first_name}}, I specialize in HVAC landing pages for Google Ads traffic. A quick look at your page can show whether it is helping or hurting ROI.', 'leftTitle' => 'Fast review', 'leftText' => 'Check the first screen, phone path, local proof, and page focus.', 'rightTitle' => 'Useful output', 'rightText' => 'A few specific fixes you can use even if we never work together.', 'cardTitle' => '2-minute landing page review', 'cardDescription' => 'Send the page your ads land on and I will point out the biggest conversion leak.', 'cardLabel' => 'Quick, practical, page-specific', 'cta' => 'Get the 2-minute review'],
        ['name' => 'Final Direct Offer', 'subject' => 'I build HVAC landing pages for Google Ads ROI', 'title' => 'I build HVAC landing pages that help paid clicks become calls', 'subtitle' => 'The offer is simple: improve the page your Google Ads traffic already sees.', 'intro' => 'Hi {{first_name}}, I help HVAC businesses improve ROI from Google Ads by rebuilding the landing page experience after the click.', 'leftTitle' => 'Not ad management', 'leftText' => 'I am not asking to replace your ad setup.', 'rightTitle' => 'Post-click improvement', 'rightText' => 'I focus on the page that converts those ad clicks into calls or quote requests.', 'cardTitle' => 'HVAC Google Ads landing page', 'cardDescription' => 'Service-specific, mobile-first, trust-led, and built around calls.', 'cardLabel' => 'Turn clicks into real calls', 'cta' => 'See the HVAC landing page offer'],
    ];

    $templates = [];
    foreach ($angles as $index => $angle) {
        $number = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
        $palette = $palettes[$index % count($palettes)];
        $ctaUrl = $ctaBaseUrl . '?utm_source=mailpilot&utm_medium=email&utm_campaign=hvac_cold_' . $number;
        $state = hvacColdTemplateState($number, $angle, $palette, $ctaUrl);

        $templates[] = [
            'name' => 'HVAC Cold ' . $number . ' - ' . $angle['name'],
            'subject' => $angle['subject'],
            'body_html' => hvacColdTemplateBodyHtml($state),
        ];
    }

    return $templates;
}

function hvacColdTemplateState($number, $angle, $palette, $ctaUrl) {
    $layout = ((int) $number - 1) % 4;
    $accent = $palette['accent'];
    $blocks = [];

    $hero = hvacColdTemplateBlock('hero', $number, [
        'eyebrow' => 'HVAC GOOGLE ADS ROI',
        'title' => $angle['title'],
        'subtitle' => $angle['subtitle'],
        'buttonText' => $angle['cta'],
        'buttonUrl' => $ctaUrl,
        'align' => $layout === 1 ? 'left' : 'center',
        'bg' => $palette['heroBg'],
        'textColor' => $palette['textColor'],
        'padding' => $layout === 2 ? 46 : 40,
    ]);

    $intro = hvacColdTemplateBlock('text', $number, [
        'content' => $angle['intro'] . "\n\n" . 'That is where I help: I build HVAC landing pages designed for the Google Ads traffic you already pay for, so the page does a better job turning clicks into calls.',
        'fontSize' => 16,
        'color' => $palette['muted'],
        'align' => 'left',
        'padding' => 28,
    ]);

    $compare = hvacColdTemplateBlock('twoColumn', $number, [
        'leftTitle' => $angle['leftTitle'],
        'leftText' => $angle['leftText'],
        'rightTitle' => $angle['rightTitle'],
        'rightText' => $angle['rightText'],
        'bg' => '#ffffff',
        'color' => '#334155',
        'padding' => 26,
    ]);

    $offer = hvacColdTemplateBlock('product', $number, [
        'title' => $angle['cardTitle'],
        'description' => $angle['cardDescription'],
        'price' => $angle['cardLabel'],
        'buttonText' => $angle['cta'],
        'buttonUrl' => $ctaUrl,
        'bg' => $palette['heroBg'],
        'padding' => 28,
    ]);

    $button = hvacColdTemplateBlock('button', $number, [
        'text' => $angle['cta'],
        'url' => $ctaUrl,
        'align' => 'center',
        'bg' => $accent,
        'color' => '#ffffff',
        'padding' => 22,
    ]);

    $divider = hvacColdTemplateBlock('divider', $number, [
        'color' => '#e2e8f0',
        'thickness' => 1,
        'padding' => 12,
    ]);

    $footer = hvacColdTemplateBlock('text', $number, [
        'content' => 'If improving Google Ads landing page ROI is not a priority right now, no problem.' . "\n" . 'Unsubscribe here: {{unsubscribe_link}}',
        'fontSize' => 12,
        'color' => '#64748b',
        'align' => 'center',
        'padding' => 22,
    ]);

    if ($layout === 0) {
        $blocks = [$hero, $intro, $compare, $button, $footer];
    } elseif ($layout === 1) {
        $blocks = [$intro, $hero, $offer, $button, $footer];
    } elseif ($layout === 2) {
        $blocks = [$hero, $compare, $intro, $divider, $button, $footer];
    } else {
        $blocks = [$hero, $intro, $offer, $compare, $button, $footer];
    }

    return [
        'settings' => [
            'bg' => $palette['bg'],
            'contentBg' => $palette['contentBg'],
            'accent' => $accent,
            'font' => $layout === 2 ? 'Montserrat' : 'Poppins',
        ],
        'blocks' => $blocks,
    ];
}

function hvacColdTemplateBlock($type, $number, $data) {
    static $counter = 0;
    $counter++;

    return array_merge([
        'id' => 'hvac_' . $number . '_' . $counter,
        'type' => $type,
    ], $data);
}

function hvacColdTemplateBodyHtml($state) {
    $encodedState = base64_encode(json_encode($state, JSON_UNESCAPED_SLASHES));
    return '<!--MAILPILOT_BUILDER ' . $encodedState . "-->\n" . hvacColdTemplateRenderHtml($state);
}

function hvacColdTemplateRenderHtml($state) {
    $rows = '';
    foreach ($state['blocks'] as $block) {
        $rows .= hvacColdTemplateRenderBlock($block, $state);
    }

    $bg = hvacColdTemplateAttr($state['settings']['bg']);
    $contentBg = hvacColdTemplateAttr($state['settings']['contentBg']);
    $fontStack = hvacColdTemplateAttr(hvacColdTemplateFontStack($state['settings']['font']));
    $fontImport = hvacColdTemplateFontImport($state['settings']['font']);

    return '<!doctype html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">' . $fontImport . '</head>
<body style="margin:0; padding:0; background:' . $bg . '; font-family:' . $fontStack . ';">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; background:' . $bg . '; border-collapse:collapse;">
<tr>
<td align="center" style="padding:24px 12px;">
<table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%; max-width:640px; background:' . $contentBg . '; border-collapse:collapse; font-family:' . $fontStack . ';">
' . $rows . '
</table>
</td>
</tr>
</table>
</body>
</html>';
}

function hvacColdTemplateRenderBlock($block, $state) {
    $type = $block['type'];
    $accent = $state['settings']['accent'];

    if ($type === 'hero') {
        $button = '';
        if (!empty($block['buttonText'])) {
            $button = '<a href="' . hvacColdTemplateAttr($block['buttonUrl']) . '" style="display:inline-block; background:' . hvacColdTemplateAttr($accent) . '; color:#ffffff; text-decoration:none; padding:13px 22px; border-radius:4px; font-weight:bold;">' . hvacColdTemplateEsc($block['buttonText']) . '</a>';
        }

        return '<tr><td align="' . hvacColdTemplateAttr($block['align']) . '" style="background:' . hvacColdTemplateAttr($block['bg']) . '; color:' . hvacColdTemplateAttr($block['textColor']) . '; padding:' . ((int) $block['padding']) . 'px 34px; text-align:' . hvacColdTemplateAttr($block['align']) . ';">
            <div style="font-size:12px; font-weight:bold; letter-spacing:1px; color:' . hvacColdTemplateAttr($accent) . '; margin-bottom:10px;">' . hvacColdTemplateEsc($block['eyebrow']) . '</div>
            <div style="font-size:34px; line-height:1.15; font-weight:bold; margin-bottom:14px;">' . hvacColdTemplateEsc($block['title']) . '</div>
            <div style="font-size:16px; line-height:1.65; margin-bottom:22px;">' . hvacColdTemplateLines($block['subtitle']) . '</div>
            ' . $button . '
        </td></tr>';
    }

    if ($type === 'text') {
        return '<tr><td align="' . hvacColdTemplateAttr($block['align']) . '" style="padding:' . ((int) $block['padding']) . 'px 34px; text-align:' . hvacColdTemplateAttr($block['align']) . '; color:' . hvacColdTemplateAttr($block['color']) . '; font-size:' . ((int) $block['fontSize']) . 'px; line-height:1.7;">' . hvacColdTemplateLines($block['content']) . '</td></tr>';
    }

    if ($type === 'twoColumn') {
        return '<tr><td style="padding:' . ((int) $block['padding']) . 'px 34px; background:' . hvacColdTemplateAttr($block['bg']) . '; color:' . hvacColdTemplateAttr($block['color']) . ';">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                <tr>
                    <td width="50%" valign="top" style="padding-right:10px;">
                        <div style="font-size:18px; font-weight:bold; margin-bottom:8px;">' . hvacColdTemplateEsc($block['leftTitle']) . '</div>
                        <div style="font-size:14px; line-height:1.65;">' . hvacColdTemplateLines($block['leftText']) . '</div>
                    </td>
                    <td width="50%" valign="top" style="padding-left:10px;">
                        <div style="font-size:18px; font-weight:bold; margin-bottom:8px;">' . hvacColdTemplateEsc($block['rightTitle']) . '</div>
                        <div style="font-size:14px; line-height:1.65;">' . hvacColdTemplateLines($block['rightText']) . '</div>
                    </td>
                </tr>
            </table>
        </td></tr>';
    }

    if ($type === 'product') {
        return '<tr><td style="padding:' . ((int) $block['padding']) . 'px 34px; background:' . hvacColdTemplateAttr($block['bg']) . ';">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                <tr>
                    <td valign="middle">
                        <div style="font-size:22px; font-weight:bold; color:#111827; margin-bottom:8px;">' . hvacColdTemplateEsc($block['title']) . '</div>
                        <div style="font-size:14px; color:#475569; line-height:1.6; margin-bottom:10px;">' . hvacColdTemplateLines($block['description']) . '</div>
                        <div style="font-size:18px; font-weight:bold; color:#111827; margin-bottom:14px;">' . hvacColdTemplateEsc($block['price']) . '</div>
                        <a href="' . hvacColdTemplateAttr($block['buttonUrl']) . '" style="display:inline-block; background:' . hvacColdTemplateAttr($accent) . '; color:#ffffff; text-decoration:none; padding:11px 18px; border-radius:4px; font-weight:bold;">' . hvacColdTemplateEsc($block['buttonText']) . '</a>
                    </td>
                </tr>
            </table>
        </td></tr>';
    }

    if ($type === 'button') {
        return '<tr><td align="' . hvacColdTemplateAttr($block['align']) . '" style="padding:' . ((int) $block['padding']) . 'px 34px; text-align:' . hvacColdTemplateAttr($block['align']) . ';"><a href="' . hvacColdTemplateAttr($block['url']) . '" style="display:inline-block; background:' . hvacColdTemplateAttr($block['bg']) . '; color:' . hvacColdTemplateAttr($block['color']) . '; text-decoration:none; padding:13px 24px; border-radius:4px; font-weight:bold;">' . hvacColdTemplateEsc($block['text']) . '</a></td></tr>';
    }

    if ($type === 'divider') {
        return '<tr><td style="padding:' . ((int) $block['padding']) . 'px 34px;"><div style="border-top:' . ((int) $block['thickness']) . 'px solid ' . hvacColdTemplateAttr($block['color']) . '; line-height:1px; font-size:1px;">&nbsp;</div></td></tr>';
    }

    return '';
}

function hvacColdTemplateFontStack($font) {
    if ($font === 'Montserrat') {
        return "'Montserrat', Arial, Helvetica, sans-serif";
    }
    return "'Poppins', Arial, Helvetica, sans-serif";
}

function hvacColdTemplateFontImport($font) {
    if ($font === 'Montserrat') {
        return '<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    }
    return '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
}

function hvacColdTemplateEsc($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function hvacColdTemplateAttr($value) {
    return str_replace(["\r", "\n"], ' ', hvacColdTemplateEsc($value));
}

function hvacColdTemplateLines($value) {
    return nl2br(hvacColdTemplateEsc($value));
}
