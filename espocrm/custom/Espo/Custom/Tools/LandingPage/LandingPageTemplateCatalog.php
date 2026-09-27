<?php

namespace Espo\Custom\Tools\LandingPage;

/** Platform-owned, launch-ready page designs copied into tenant drafts. */
final class LandingPageTemplateCatalog
{
    /** @return array<int, array<string, mixed>> */
    public static function get(string $siteUrl): array
    {
        return [self::requestDemo($siteUrl), self::consultation($siteUrl), self::eventRegistration($siteUrl), self::leadMagnet($siteUrl)];
    }

    /** @return array<string, mixed> */
    private static function requestDemo(string $siteUrl): array
    {
        return self::template($siteUrl, 'request-demo', 'Request a Demo', 'Lead generation', 'A polished product story for turning high-intent visitors into qualified demo conversations.', 'request-demo.jpg', [
            'name'=>'Request a Demo','slug'=>'request-a-demo','seoTitle'=>'See a smarter customer workspace in action','seoDescription'=>'Book a tailored demonstration and see how one connected workspace helps your team grow customer relationships.','primaryColor'=>'#087d71','textColor'=>'#183238',
            'blocks'=>[
                self::block('demo-hero','hero',['eyebrow'=>'A clearer way to grow','heading'=>'Turn every customer signal into the right next action','text'=>'See how one connected workspace gives sales, marketing and service teams the context to move faster without losing the human story.','buttonLabel'=>'Book your live demo','buttonUrl'=>'#demo-form','templateImage'=>'request-demo.jpg','altText'=>'A business team discussing customer growth in a modern office']),
                self::block('demo-stats','stats',['heading'=>'Built for the whole customer journey','text'=>'From first interest to long-term growth, every team works from the same trusted record.','items'=>[['title'=>'1','text'=>'Connected customer record'],['title'=>'360 degrees','text'=>'Relationship visibility'],['title'=>'24/7','text'=>'Signals ready for action']]]),
                self::block('demo-features','features',['eyebrow'=>'What you will see','heading'=>'A demonstration shaped around your team','text'=>'No generic product tour. We focus on the workflows, data and outcomes that matter to your business.','items'=>[['title'=>'One customer story','text'=>'See conversations, consent, activity and commercial history together.'],['title'=>'Work that moves itself','text'=>'Route follow-ups, surface priorities and keep ownership clear.'],['title'=>'Answers leaders can trust','text'=>'Connect frontline activity with pipeline, service and revenue outcomes.']]]),
                self::block('demo-proof','testimonial',['heading'=>'We stopped piecing the customer story together across five different tools.','text'=>'The team now starts every conversation with the context it needs.','items'=>[['title'=>'Maya Chen','text'=>'Revenue Operations Director','meta'=>'Northstar Digital']]]),
                self::block('demo-form','form',['eyebrow'=>'Your tailored walkthrough','heading'=>'Book a conversation with our team','text'=>'Tell us what you want to improve. We will prepare a focused 30-minute demonstration for your priorities.']),
                self::block('demo-cta','cta',['heading'=>'Bring one real workflow to the call','text'=>'We will show you exactly how it could work in a connected customer workspace.','buttonLabel'=>'Choose a time','buttonUrl'=>'#demo-form']),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private static function consultation(string $siteUrl): array
    {
        return self::template($siteUrl, 'consultation', 'Consultation & Quote', 'Professional services', 'A credible advisory page for consultation, assessment and quote requests.', 'consultation.jpg', [
            'name'=>'Consultation Request','slug'=>'consultation','seoTitle'=>'Book a practical business consultation','seoDescription'=>'Discuss your goals with an experienced adviser and leave with a clear route forward.','primaryColor'=>'#2f6b52','textColor'=>'#202d2a',
            'blocks'=>[
                self::block('consult-hero','hero',['eyebrow'=>'Advice grounded in your reality','heading'=>'Make the next business decision with evidence, not guesswork','text'=>'Bring us the challenge. We will help you clarify the options, understand the trade-offs and shape a practical plan your team can act on.','buttonLabel'=>'Request a consultation','buttonUrl'=>'#consult-form','templateImage'=>'consultation.jpg','altText'=>'Senior business advisers reviewing evidence around a meeting table']),
                self::block('consult-features','features',['eyebrow'=>'A useful first conversation','heading'=>'Clarity before commitment','text'=>'Our consultation is designed to give you value before a proposal is ever discussed.','items'=>[['title'=>'Understand the problem','text'=>'Separate symptoms from the underlying operational or commercial issue.'],['title'=>'Compare your options','text'=>'Review realistic paths, risks, dependencies and likely return.'],['title'=>'Leave with next steps','text'=>'Receive a concise recommendation and a transparent scope if we can help.']]]),
                self::block('consult-stats','stats',['heading'=>'Senior attention from the start','text'=>'Your first conversation is with an experienced adviser, not a qualification script.','items'=>[['title'=>'30 min','text'=>'Focused discovery'],['title'=>'48 hrs','text'=>'Written follow-up'],['title'=>'0','text'=>'Obligation to proceed']]]),
                self::block('consult-proof','testimonial',['heading'=>'The advice was direct, commercially sensible and immediately useful.','text'=>'We understood the decision we needed to make before we discussed delivery.','items'=>[['title'=>'Daniel Okafor','text'=>'Managing Partner','meta'=>'Fieldstone Advisory']]]),
                self::block('consult-form','form',['eyebrow'=>'Start here','heading'=>'Tell us what you are working through','text'=>'Share a little context and the best way to reach you. A senior adviser will respond within one business day.']),
                self::block('consult-cta','cta',['heading'=>'A better decision starts with a clearer question','text'=>'Use the consultation to test your thinking and identify the most useful next move.','buttonLabel'=>'Start the conversation','buttonUrl'=>'#consult-form']),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private static function eventRegistration(string $siteUrl): array
    {
        return self::template($siteUrl, 'event-registration', 'Event Registration', 'Events', 'A complete registration page with event value, programme highlights and attendee proof.', 'event-registration.jpg', [
            'name'=>'Growth Leaders Live','slug'=>'growth-leaders-live','seoTitle'=>'Growth Leaders Live 2027','seoDescription'=>'Join operators and customer leaders for one practical day of ideas, honest lessons and useful connections.','primaryColor'=>'#b52b4f','textColor'=>'#282331',
            'blocks'=>[
                self::block('event-hero','hero',['eyebrow'=>'14 May 2027 - London','heading'=>'The room where customer growth gets practical','text'=>'One focused day for leaders building joined-up sales, marketing and service experiences. Honest lessons, useful frameworks and people doing the work.','buttonLabel'=>'Reserve your seat','buttonUrl'=>'#event-form','templateImage'=>'event-registration.jpg','altText'=>'An audience listening to a speaker at a professional conference']),
                self::block('event-stats','stats',['heading'=>'A deliberately focused event','text'=>'Designed for useful conversations rather than an overwhelming conference schedule.','items'=>[['title'=>'250','text'=>'Growth leaders'],['title'=>'12','text'=>'Practical sessions'],['title'=>'1 day','text'=>'Ideas you can use']]]),
                self::block('event-agenda','features',['eyebrow'=>'Programme highlights','heading'=>'Leave with more than notes','text'=>'Every session is built around a real operating decision, with time to compare approaches with peers.','items'=>[['title'=>'09:30 - The connected customer','text'=>'A practical operating model for sales, marketing and service.'],['title'=>'12:00 - From signal to action','text'=>'How high-performing teams decide what deserves attention.'],['title'=>'15:00 - The honest panel','text'=>'What leaders changed, what failed and what they would do differently.']]]),
                self::block('event-proof','testimonial',['heading'=>'The rare event where every conversation gave me something useful to take back to the team.','text'=>'Focused, generous and refreshingly practical.','items'=>[['title'=>'Leila Morgan','text'=>'VP, Customer Growth','meta'=>'Attendee, 2026']]]),
                self::block('event-form','form',['eyebrow'=>'Registration','heading'=>'Join us in the room','text'=>'Reserve your place today. We will send venue details, accessibility information and your attendee pack by email.']),
                self::block('event-cta','cta',['heading'=>'Bring the challenge your team is solving now','text'=>'You will leave with practical ideas and a stronger network of people facing the same work.','buttonLabel'=>'Reserve your seat','buttonUrl'=>'#event-form']),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private static function leadMagnet(string $siteUrl): array
    {
        return self::template($siteUrl, 'lead-magnet', 'Report Download', 'Content offer', 'A premium report page that establishes value before asking a visitor to exchange their details.', 'lead-magnet.jpg', [
            'name'=>'Revenue Operations Benchmark','slug'=>'revenue-operations-benchmark','seoTitle'=>'The 2027 Revenue Operations Benchmark','seoDescription'=>'Download practical data on customer operations, pipeline confidence and connected growth.','primaryColor'=>'#176b63','textColor'=>'#1e292d',
            'blocks'=>[
                self::block('report-hero','hero',['eyebrow'=>'New research - 2027 benchmark','heading'=>'What high-confidence revenue teams do differently','text'=>'A practical benchmark for leaders aligning customer data, commercial execution and frontline decisions across the full journey.','buttonLabel'=>'Get the report','buttonUrl'=>'#report-form','templateImage'=>'lead-magnet.jpg','altText'=>'A tablet and printed research documents on a business desk']),
                self::block('report-stats','stats',['heading'=>'Research grounded in operating reality','text'=>'The findings combine quantitative benchmarks with candid interviews from the people leading the work.','items'=>[['title'=>'420','text'=>'Teams surveyed'],['title'=>'11','text'=>'Industries represented'],['title'=>'26','text'=>'Executive interviews']]]),
                self::block('report-features','features',['eyebrow'=>'Inside the report','heading'=>'Benchmarks you can put to work','text'=>'Use the data to challenge assumptions, focus investment and create a more useful operating conversation.','items'=>[['title'=>'Data confidence','text'=>'See where fragmented records still undermine decisions and customer experience.'],['title'=>'Pipeline discipline','text'=>'Compare the habits that separate visible activity from dependable forecasts.'],['title'=>'Operating priorities','text'=>'Understand where leading teams are simplifying tools, ownership and hand-offs.']]]),
                self::block('report-proof','testimonial',['heading'=>'It gave our leadership team a common language for the problems we were already feeling.','text'=>'The benchmarks helped us move from opinion to an agreed set of priorities.','items'=>[['title'=>'Amira Bello','text'=>'Chief Commercial Officer','meta'=>'Wellspring Group']]]),
                self::block('report-form','form',['eyebrow'=>'Download the research','heading'=>'Get your copy of the benchmark','text'=>'Complete the short form and we will send the report directly to your inbox.']),
                self::block('report-cta','cta',['heading'=>'Turn the benchmark into a working session','text'=>'Share it with your leadership team and use the discussion guide included in the report.','buttonLabel'=>'Download the report','buttonUrl'=>'#report-form']),
            ],
        ]);
    }

    /** @param array<string, mixed> $configuration @return array<string, mixed> */
    private static function template(string $siteUrl, string $id, string $name, string $category, string $description, string $image, array $configuration): array
    {
        return ['id'=>$id,'name'=>$name,'category'=>$category,'description'=>$description,'thumbnail'=>$siteUrl.'/client/custom/img/landing-templates/'.$image,'configuration'=>['locale'=>'en','canonicalUrl'=>null,'noIndex'=>false,'sourceTemplate'=>$id,'backgroundColor'=>'#ffffff',...$configuration]];
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private static function block(string $id, string $type, array $values): array
    {
        return ['id'=>$id,'type'=>$type,'eyebrow'=>'','heading'=>'','text'=>'','caption'=>'','buttonLabel'=>'','buttonUrl'=>null,'assetId'=>null,'formId'=>null,'templateImage'=>null,'altText'=>'','items'=>[],...$values];
    }
}
