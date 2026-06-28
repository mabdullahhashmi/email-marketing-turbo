<?php
/**
 * Default builder-compatible cold outreach templates for plumber website/page audits.
 */

function seedHVACColdOutreachTemplates() {
    ensureCampaignTemplatesTable();

    $version = '14';
    $currentVersion = null;
    try {
        $currentVersion = dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'hvac_template_pack_version' LIMIT 1");
    } catch (Exception $e) {
        $currentVersion = null;
    }

    if ($currentVersion !== $version) {
        dbExecute("DELETE FROM campaign_templates WHERE name LIKE 'HVAC Cold %' OR name LIKE 'HVAC Website Audit %'");
        $existingRows = dbFetchAll("SELECT id, name FROM campaign_templates WHERE name LIKE 'Plumber Website Audit %' OR name LIKE 'Plumber Landing Page Premium %'");
        $existingByName = [];
        foreach ($existingRows as $row) {
            $existingByName[$row['name']] = (int)$row['id'];
        }

        foreach (getHVACColdOutreachTemplates() as $template) {
            if (isset($existingByName[$template['name']])) {
                dbExecute(
                    "UPDATE campaign_templates SET subject = ?, body_html = ?, updated_at = NOW() WHERE id = ?",
                    [$template['subject'], $template['body_html'], $existingByName[$template['name']]]
                );
            } else {
                dbInsert(
                    "INSERT INTO campaign_templates (name, subject, body_html) VALUES (?, ?, ?)",
                    [$template['name'], $template['subject'], $template['body_html']]
                );
            }
        }
        dbExecute(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('hvac_template_pack_version', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [$version]
        );
        return;
    }

    $existingRows = dbFetchAll("SELECT name FROM campaign_templates WHERE name LIKE 'Plumber Website Audit %' OR name LIKE 'Plumber Landing Page Premium %'");
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
    $websiteBaseUrl = 'https://abdullahhashmi.com/plumbers-growth-expert/';
    $calendlyBaseUrl = 'https://calendly.com/mu-abdullahhashmi/30min';

    $themes = [
        ['bg' => '#eaf1f8', 'contentBg' => '#ffffff', 'accent' => '#2563eb', 'heroBg' => '#0f2f63', 'heroText' => '#ffffff', 'panelBg' => '#eff6ff', 'panelBorder' => '#bfdbfe'],
        ['bg' => '#edf7f1', 'contentBg' => '#ffffff', 'accent' => '#10b981', 'heroBg' => '#063f3a', 'heroText' => '#ffffff', 'panelBg' => '#ecfdf5', 'panelBorder' => '#a7f3d0'],
        ['bg' => '#fff4e8', 'contentBg' => '#ffffff', 'accent' => '#f97316', 'heroBg' => '#172033', 'heroText' => '#ffffff', 'panelBg' => '#fff7ed', 'panelBorder' => '#fed7aa'],
        ['bg' => '#eef2ff', 'contentBg' => '#ffffff', 'accent' => '#4f46e5', 'heroBg' => '#1e1b4b', 'heroText' => '#ffffff', 'panelBg' => '#eef2ff', 'panelBorder' => '#c7d2fe'],
        ['bg' => '#f8fafc', 'contentBg' => '#ffffff', 'accent' => '#0ea5e9', 'heroBg' => '#082f49', 'heroText' => '#ffffff', 'panelBg' => '#e0f2fe', 'panelBorder' => '#bae6fd'],
        ['bg' => '#fdf2f8', 'contentBg' => '#ffffff', 'accent' => '#db2777', 'heroBg' => '#500724', 'heroText' => '#ffffff', 'panelBg' => '#fce7f3', 'panelBorder' => '#fbcfe8'],
        ['bg' => '#f7fee7', 'contentBg' => '#ffffff', 'accent' => '#65a30d', 'heroBg' => '#1f2a11', 'heroText' => '#ffffff', 'panelBg' => '#ecfccb', 'panelBorder' => '#d9f99d'],
        ['bg' => '#f0fdfa', 'contentBg' => '#ffffff', 'accent' => '#0f766e', 'heroBg' => '#042f2e', 'heroText' => '#ffffff', 'panelBg' => '#ccfbf1', 'panelBorder' => '#99f6e4'],
    ];

    $angles = [
        ['key' => 'Quote Requests', 'problem' => 'Plumbing visitors are landing on the site, but not enough of them become quote requests.', 'promise' => 'Make the page clearer, faster, and easier to act on.', 'auditIssue' => 'Quote request path is too hidden', 'score' => 'CTA: 31/100'],
        ['key' => 'Phone Calls', 'problem' => 'Customers who need plumbing help should not have to hunt for the phone number.', 'promise' => 'Put the call path, urgency, and trust cues where homeowners expect them.', 'auditIssue' => 'Clickable phone CTA is weak', 'score' => 'Calls: 42/100'],
        ['key' => 'Trust Signals', 'problem' => 'A visitor may leave if they do not see proof that the company is local, trusted, and reliable.', 'promise' => 'Place reviews, service areas, guarantees, and proof near the decision points.', 'auditIssue' => 'Trust proof is buried too low', 'score' => 'Trust: 38/100'],
        ['key' => 'Emergency Service', 'problem' => 'Emergency plumbing visitors need fast confidence before they call.', 'promise' => 'Make urgent help, response expectations, and call buttons obvious above the fold.', 'auditIssue' => 'Emergency CTA not visible', 'score' => 'Urgency: 35/100'],
        ['key' => 'Mobile Layout', 'problem' => 'Most local visitors check plumbing websites on mobile before calling.', 'promise' => 'Simplify the mobile layout so calls and forms are easy to reach.', 'auditIssue' => 'Mobile flow feels crowded', 'score' => 'Mobile: 44/100'],
        ['key' => 'Service Pages', 'problem' => 'Drain cleaning, water heaters, leak repair, and emergency plumbing should not all compete in one unclear section.', 'promise' => 'Separate service intent so visitors quickly find the help they need.', 'auditIssue' => 'Services are not clearly separated', 'score' => 'Clarity: 40/100'],
        ['key' => 'Local Relevance', 'problem' => 'Homeowners want to know if the company actually serves their city or area.', 'promise' => 'Add local proof, service-area context, and page sections that feel relevant.', 'auditIssue' => 'Service area proof is weak', 'score' => 'Local: 36/100'],
        ['key' => 'Speed And Friction', 'problem' => 'A slow or cluttered page can lose a visitor before they ever contact the business.', 'promise' => 'Trim the page journey and guide visitors to one clear next step.', 'auditIssue' => 'Page journey has too much friction', 'score' => 'Speed: 28/100'],
        ['key' => 'Hero Section', 'problem' => 'The first screen should explain the offer, location fit, and next step instantly.', 'promise' => 'Rewrite and redesign the hero section so it pushes visitors toward action.', 'auditIssue' => 'Headline does not sell the next step', 'score' => 'Hero: 33/100'],
        ['key' => 'Form Flow', 'problem' => 'Forms often ask too much before the visitor trusts the company.', 'promise' => 'Use a lighter quote path backed by trust and clear response expectations.', 'auditIssue' => 'Form asks before trust is built', 'score' => 'Forms: 39/100'],
        ['key' => 'Website Cleanup', 'problem' => 'Many plumber websites have the right information, but the layout does not guide action.', 'promise' => 'Reorder the page so every section supports calls, trust, or quote requests.', 'auditIssue' => 'Important sections are in the wrong order', 'score' => 'Flow: 37/100'],
        ['key' => 'Conversion Audit', 'problem' => 'Small page changes can sometimes make a noticeable difference in calls and enquiries.', 'promise' => 'Send a clear audit with 2-3 practical improvements for the current website.', 'auditIssue' => 'No clear conversion path', 'score' => 'Audit: 41/100'],
    ];

    $styles = [
        ['label' => 'Direct Audit', 'subject' => 'Quick idea for your plumbing website', 'title' => 'Is your plumbing website turning visitors into real quote requests?', 'cta' => 'Get Free Website Audit', 'layout' => 'cards'],
        ['label' => 'Visual Audit', 'subject' => 'I noticed a common plumbing website issue', 'title' => 'A stronger page can help more local visitors take action', 'cta' => 'View Website Example', 'layout' => 'mockup'],
        ['label' => 'Fix List', 'subject' => 'Small plumbing website changes can add up', 'title' => 'Small website changes can make a big difference.', 'cta' => 'Get Free Page Audit', 'layout' => 'checklist'],
        ['label' => 'Service Offer', 'subject' => 'Want me to check your plumbing website?', 'title' => 'I can send a practical plumbing website audit', 'cta' => 'Get Service', 'layout' => 'metrics'],
    ];

    $templates = [];
    $count = 0;
    foreach ($angles as $angle) {
        foreach ($styles as $style) {
            $count++;
            $number = str_pad((string) $count, 2, '0', STR_PAD_LEFT);
            $theme = $themes[($count - 1) % count($themes)];
            $auditUrl = $calendlyBaseUrl . '?utm_source=mailpilot&utm_medium=email&utm_campaign=plumber_audit_booking_' . $number;
            $websiteUrl = $websiteBaseUrl . '?utm_source=mailpilot&utm_medium=email&utm_campaign=plumber_how_it_works_' . $number;
            $state = hvacWebsiteAuditTemplateState($number, $angle, $style, $theme, $auditUrl, $websiteUrl);

            $templates[] = [
                'name' => 'Plumber Website Audit ' . $number . ' - ' . $angle['key'] . ' ' . $style['label'],
                'subject' => $style['subject'] . ' - ' . $angle['key'],
                'body_html' => hvacWebsiteAuditTemplateBodyHtml($state),
            ];
        }
    }

    $templates[] = [
        'name' => 'Plumber Landing Page Premium 01 - Leak Audit Visual',
        'subject' => 'Stop losing jobs to a weak landing page',
        'body_html' => hvacWebsiteAuditTemplateBodyHtml(plumberPremiumLandingPageTemplateState($calendlyBaseUrl, $websiteBaseUrl)),
    ];

    return $templates;
}

function plumberPremiumLandingPageTemplateState($calendlyBaseUrl, $websiteBaseUrl) {
    $auditUrl = $calendlyBaseUrl . '?utm_source=mailpilot&utm_medium=email&utm_campaign=plumber_premium_leak_audit';
    $websiteUrl = $websiteBaseUrl . '?utm_source=mailpilot&utm_medium=email&utm_campaign=plumber_premium_how_it_works';

    return [
        'settings' => [
            'bg' => '#f7f9fc',
            'contentBg' => '#ffffff',
            'accent' => '#f47c20',
            'font' => 'Poppins',
            'width' => 580,
        ],
        'blocks' => [
            hvacWebsiteAuditBlock('premiumPlumberHeader', 'premium', [
                'brand' => 'Abdullah',
                'tagline' => 'GROWTH EXPERT',
                'rightText' => 'Helping Plumbers Get More Jobs Online',
                'dotColor' => '#f47c20',
                'bg' => '#ffffff',
                'color' => '#0b1d3a',
                'muted' => '#8fa3bf',
                'padding' => 26,
            ]),
            hvacWebsiteAuditBlock('premiumPlumberHeroScore', 'premium', [
                'pill' => 'A quick idea for you',
                'title' => 'Your landing page could be costing you',
                'titleAccent' => 'new customers.',
                'text' => 'We help plumbing businesses turn more traffic into booked jobs with high-converting landing pages built for calls, quote requests and emergency leads.',
                'stat1Title' => 'More Leads',
                'stat1Text' => 'from the same traffic',
                'stat2Title' => 'Lower Cost',
                'stat2Text' => 'per qualified lead',
                'stat3Title' => 'More Booked',
                'stat3Text' => 'jobs on calendar',
                'heroButtonText' => 'Book Free Audit',
                'heroButtonUrl' => $auditUrl,
                'heroSecondaryButtonText' => 'See How It Works',
                'heroSecondaryButtonUrl' => $websiteUrl,
                'note' => 'No obligation. Just value.',
                'cardPill' => 'Free audit preview',
                'cardMeta' => '2-min scan',
                'cardImageUrl' => 'https://abdullahhashmi.com/wp-content/uploads/2026/06/plumber-email-image.jpg',
                'cardTitle' => 'Landing Page' . "\n" . 'Leak Scorecard',
                'cardText' => 'A quick plumbing-focused check to show where leads may be dropping off.',
                'score' => '62',
                'scoreLabel' => 'Lead leak score',
                'check1Title' => 'CTA Visibility',
                'check1Text' => 'Is the call button obvious?',
                'check2Title' => 'Mobile Quote Flow',
                'check2Text' => 'Can users request fast?',
                'check3Title' => 'Trust Proof',
                'check3Text' => 'Reviews before the CTA?',
                'bottom1' => 'Calls',
                'bottom2' => 'Quotes',
                'bottom3' => 'Bookings',
                'bg' => '#0b1d3a',
                'bg2' => '#0f2a55',
                'accent' => '#f47c20',
                'muted' => '#8fa3bf',
                'padding' => 28,
            ]),
            hvacWebsiteAuditBlock('premiumPlumberFindings', 'premium', [
                'eyebrow' => 'What we found',
                'title' => 'Small changes.' . "\n" . 'Big results.',
                'text' => 'These quick wins can make a big difference when local customers are ready to book.',
                'item1' => 'Stronger headline could increase conversions by improving first-click clarity',
                'item2' => 'Mobile experience issues may be losing emergency plumbing leads',
                'item3' => 'Shorter, trust-driven form can increase quote submissions',
                'bg' => '#0b1d3a',
                'bg2' => '#0f2a55',
                'accent' => '#f47c20',
                'padding' => 32,
            ]),
            hvacWebsiteAuditBlock('premiumPlumberProcess', 'premium', [
                'eyebrow' => 'Our Process',
                'title' => 'Strategy. Design. Results.',
                'text' => 'We design and optimize landing pages specifically for plumbing businesses so you get more calls, more leads, and more booked jobs.',
                'item1Title' => 'Conversion Focused',
                'item1Text' => 'Every element is built to convert visitors into leads.',
                'item2Title' => 'Speed Optimized',
                'item2Text' => 'Fast-loading pages that rank better and convert more.',
                'item3Title' => 'Trust Built-In',
                'item3Text' => 'Proof signals that turn visitors into buyers.',
                'item4Title' => 'Data Driven',
                'item4Text' => 'Continuous testing and optimization for maximum results.',
                'accent' => '#f47c20',
                'padding' => 32,
            ]),
            hvacWebsiteAuditBlock('premiumPlumberIncludes', 'premium', [
                'title' => "What's Included",
                'item1' => 'Conversion-Focused' . "\n" . 'Design',
                'item2' => 'Mobile-Friendly' . "\n" . 'Pages',
                'item3' => 'Fast Load' . "\n" . 'Speed',
                'item4' => 'Clear CTAs' . "\n" . 'That Convert',
                'accent' => '#f47c20',
                'padding' => 28,
            ]),
            hvacWebsiteAuditBlock('premiumLeakHero', 'premium', [
                'titleLine1' => 'Stop Losing Jobs',
                'titleLine2' => 'to a',
                'titleAccent' => 'Weak Landing Page.',
                'text' => "Let's build a page that brings you more calls, more bookings, and more revenue.",
                'buttonText' => 'Get My Free Landing Page Audit',
                'buttonUrl' => $auditUrl,
                'visualTop' => 'FREE',
                'visualLine1' => 'Landing Page',
                'visualLine2' => 'Audit',
                'bg' => '#0b1d3a',
                'textColor' => '#ffffff',
                'muted' => '#d9e6f8',
                'accent' => '#f47c20',
                'buttonBg' => '#f47c20',
                'padding' => 0,
            ]),
            hvacWebsiteAuditBlock('premiumImpactDice', 'premium', [
                'smallWord' => 'Small',
                'smallTail' => 'moves.',
                'bigWord' => 'Big',
                'bigTail' => ' impact.',
                'text' => "Big impact doesn't come from one big step. It comes from making the small things clear, fast, and easy to act on.",
                'buttonText' => 'Start Today',
                'buttonUrl' => $auditUrl,
                'accent' => '#f47c20',
                'buttonBg' => '#0a1f3d',
                'padding' => 18,
            ]),
            hvacWebsiteAuditBlock('premiumCompare', 'premium', [
                'title' => 'Convert or Leak..?',
                'leftLabel' => 'with optimization',
                'leftPercent' => '68%',
                'leftTitle' => 'more quote requests',
                'leftText' => 'from clearer page flow',
                'rightLabel' => 'without optimization',
                'rightPercent' => '18%',
                'rightTitle' => 'visitors bounce',
                'rightText' => 'before they call',
                'bg' => '#0a1f3d',
                'accent' => '#ff8a32',
                'padding' => 18,
            ]),
            hvacWebsiteAuditBlock('premiumPlumberFinalCta', 'premium', [
                'title' => 'Ready to turn more clicks into booked plumbing jobs?',
                'text' => "Send us your landing page and we'll show the biggest conversion leaks.",
                'buttonText' => 'Book Free Audit',
                'buttonUrl' => $auditUrl,
                'note' => 'No pressure. No hard pitch.',
                'bg' => '#fff5eb',
                'border' => '#fbd6bd',
                'accent' => '#f47c20',
                'padding' => 28,
            ]),
            hvacWebsiteAuditBlock('premiumPlumberFooter', 'premium', [
                'brand' => 'Abdullah',
                'tagline' => 'GROWTH EXPERT',
                'text' => 'Specialized landing pages for plumbers who want more calls and fewer lost leads.',
                'title' => "Let's Grow Your Plumbing Business",
                'phone' => '+92 308 7667665',
                'note' => "You're receiving this email because we thought your business could benefit from a better landing page. Unsubscribe: {{unsubscribe_link}}",
                'bg' => '#f7f9fc',
                'accent' => '#f47c20',
                'muted' => '#4a6080',
                'padding' => 26,
            ]),
        ],
    ];
}

function hvacWebsiteAuditTemplateState($number, $angle, $style, $theme, $auditUrl, $websiteUrl) {
    $blocks = [
        hvacWebsiteAuditBlock('brandHeader', $number, [
            'brand' => 'Abdullah Hashmi',
            'label' => 'Website & Landing Page Specialist',
            'bg' => '#0f172a',
            'color' => '#ffffff',
            'padding' => 18,
        ]),
        hvacWebsiteAuditBlock('hero', $number, [
            'eyebrow' => 'FOR PLUMBING BUSINESS OWNERS',
            'title' => $style['title'],
            'subtitle' => 'I help plumbing companies improve website and landing pages so more local visitors call, request a quote, or book service.',
            'buttonText' => 'Get Free Audit',
            'buttonUrl' => $auditUrl,
            'secondaryButtonText' => 'See How It Works',
            'secondaryButtonUrl' => $websiteUrl,
            'secondaryButtonBg' => '#ffffff',
            'secondaryButtonColor' => '#0f172a',
            'imageUrl' => '',
            'align' => 'left',
            'bg' => $theme['heroBg'],
            'textColor' => $theme['heroText'],
            'padding' => 46,
        ]),
        hvacWebsiteAuditBlock('text', $number, [
            'content' => 'Hi {{first_name}},' . "\n\n" . $angle['problem'] . "\n\n" . $angle['promise'] . ' I can take a look and send a short audit with practical page improvements.',
            'fontSize' => 16,
            'color' => '#0f172a',
            'align' => 'left',
            'padding' => 30,
        ]),
    ];

    if ($style['layout'] === 'cards') {
        $blocks[] = hvacTemplateAuditGrid($number, $theme);
        $blocks[] = hvacTemplateChecklist($number, $theme, $angle);
    } elseif ($style['layout'] === 'mockup') {
        $blocks[] = hvacTemplateBrowserAudit($number, $theme, $angle);
        $blocks[] = hvacTemplateAuditGrid($number, $theme);
    } elseif ($style['layout'] === 'checklist') {
        $blocks[] = hvacTemplateChecklist($number, $theme, $angle);
        $blocks[] = hvacTemplateAuditGrid($number, $theme);
    } else {
        $blocks[] = hvacTemplateMetricBars($number, $theme);
        $blocks[] = hvacTemplateBrowserAudit($number, $theme, $angle);
    }

    $blocks[] = hvacWebsiteAuditBlock('ctaPanel', $number, [
        'title' => 'Want me to check your website?',
        'text' => 'I can send a quick free audit with 2-3 improvements that may help your plumbing website get more calls and quote requests. You can also reply to this email with your website URL.',
        'buttonText' => 'Get Free Audit',
        'buttonUrl' => $auditUrl,
        'secondaryButtonText' => 'How It Works',
        'secondaryButtonUrl' => $websiteUrl,
        'bg' => $theme['panelBg'],
        'border' => $theme['panelBorder'],
        'buttonBg' => $theme['accent'],
        'secondaryButtonBg' => '#ffffff',
        'secondaryButtonColor' => '#0f172a',
        'color' => '#0f172a',
        'padding' => 30,
    ]);
    $blocks[] = hvacWebsiteAuditBlock('signature', $number, [
        'name' => 'Abdullah Hashmi',
        'title' => 'Website & Landing Page Specialist',
        'website' => 'abdullahhashmi.com',
        'note' => 'You are receiving this because I thought this may be relevant to your business. If not interested, reply "not interested" and I will not contact you again. Unsubscribe: {{unsubscribe_link}}',
        'avatarText' => 'AH',
        'bg' => '#ffffff',
        'color' => '#0f172a',
        'muted' => '#64748b',
        'padding' => 24,
    ]);

    return [
        'settings' => [
            'bg' => $theme['bg'],
            'contentBg' => $theme['contentBg'],
            'accent' => $theme['accent'],
            'font' => $number % 3 === 0 ? 'Montserrat' : 'Poppins',
        ],
        'blocks' => $blocks,
    ];
}

function hvacTemplateAuditGrid($number, $theme) {
    return hvacWebsiteAuditBlock('auditGrid', $number, [
        'item1Title' => 'More Calls',
        'item1Text' => 'Clear call buttons and mobile-first layout for customers who need service fast.',
        'item1Icon' => 'Call',
        'item2Title' => 'Quote Requests',
        'item2Text' => 'Better forms and CTA sections to collect serious enquiries from homeowners.',
        'item2Icon' => 'Quote',
        'item3Title' => 'More Trust',
        'item3Text' => 'Reviews, service areas, guarantees, and trust elements placed properly.',
        'item3Icon' => 'Trust',
        'item4Title' => 'Faster Action',
        'item4Text' => 'Simple page flow so visitors understand your offer and contact quickly.',
        'item4Icon' => 'Fast',
        'bg' => '#ffffff',
        'cardBg' => '#f8fafc',
        'border' => '#dbe3ef',
        'iconBg' => $theme['panelBg'],
        'iconColor' => $theme['accent'],
        'padding' => 26,
    ]);
}

function hvacTemplateChecklist($number, $theme, $angle) {
    return hvacWebsiteAuditBlock('checklistPanel', $number, [
        'title' => 'Small website changes can make a big difference.',
        'intro' => 'Most plumbing websites already have the services listed. The real issue is how the page guides the visitor toward taking action.',
        'item1' => 'Strong headline focused on customer problems',
        'item2' => 'Emergency service CTA above the fold',
        'item3' => 'Separate sections for drain cleaning, leak repair, water heaters, and emergency service',
        'item4' => $angle['promise'],
        'bg' => '#0f172a',
        'color' => '#ffffff',
        'lineColor' => '#26364f',
        'accent' => $theme['accent'],
        'padding' => 28,
    ]);
}

function hvacTemplateMetricBars($number, $theme) {
    return hvacWebsiteAuditBlock('metricBars', $number, [
        'title' => 'Before vs After - Key Website Metrics',
        'subtitle' => 'Illustrative improvements a stronger page journey can support',
        'metric1Label' => 'Calls / Week',
        'metric1Before' => '2',
        'metric1After' => '9',
        'metric1Note' => 'More calls',
        'metric2Label' => 'Page Speed',
        'metric2Before' => '28',
        'metric2After' => '96',
        'metric2Note' => 'Faster page',
        'metric3Label' => 'Quote Rate',
        'metric3Before' => '1.1%',
        'metric3After' => '5.8%',
        'metric3Note' => 'More enquiries',
        'bg' => '#17345a',
        'color' => '#ffffff',
        'muted' => '#a8b5c7',
        'accent' => $theme['accent'],
        'padding' => 32,
    ]);
}

function hvacTemplateBrowserAudit($number, $theme, $angle) {
    return hvacWebsiteAuditBlock('browserAudit', $number, [
        'label' => 'Typical Plumbing Website Right Now',
        'domain' => 'plumberyourcity.com',
        'score' => $angle['score'],
        'issue1' => $angle['auditIssue'],
        'issue2' => 'Not mobile-optimized',
        'issue3' => 'No clear quote request section',
        'issue4' => 'Trust signals or reviews are too low',
        'bg' => '#ffffff',
        'warningBg' => '#fef2f2',
        'warningColor' => '#dc2626',
        'chromeBg' => '#e5eaf1',
        'padding' => 28,
    ]);
}

function hvacWebsiteAuditBlock($type, $number, $data) {
    static $counter = 0;
    $counter++;

    return array_merge([
        'id' => 'hvac_v3_' . $number . '_' . $counter,
        'type' => $type,
    ], $data);
}

function hvacWebsiteAuditTemplateBodyHtml($state) {
    return mailpilotBuilderStateToHtml($state);
}

function hvacWebsiteAuditFallbackHtml($state) {
    $title = 'Plumber Website Audit';
    foreach ($state['blocks'] as $block) {
        if (($block['type'] ?? '') === 'hero') {
            $title = $block['title'] ?? $title;
            break;
        }
    }

    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,Helvetica,sans-serif;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:24px;"><table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;background:#ffffff;"><tr><td style="padding:34px;font-size:24px;font-weight:bold;color:#0f172a;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</td></tr><tr><td style="padding:0 34px 34px 34px;color:#475569;line-height:1.7;">Open this saved template in the Mailpilot builder to edit the full visual email design.</td></tr></table></td></tr></table></body></html>';
}
