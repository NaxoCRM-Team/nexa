define('custom:product-surface-registry', [], () => {
    // This registry is the application-level contract for planned workspaces.
    // A module activates a surface only after its route, authorization and data contracts exist.
    const navigation = [
        {name: 'nexa-crm', label: 'CRM', iconClass: 'fas fa-address-book', items: [
            'Account', 'Contact', 'Lead',
            ['nexa-customer-timeline', 'Customer Timeline'],
            ['nexa-lists-segments', 'Lists & Segments'],
            ['nexa-lifecycle', 'Lifecycle'],
            ['nexa-custom-objects', 'Custom Objects'],
        ]},
        {name: 'nexa-sales', label: 'Sales', iconClass: 'fas fa-chart-line', items: [
            'Opportunity', 'Meeting', 'Call', 'Task', 'Calendar',
            ['nexa-sales-pipelines', 'Pipelines'],
            ['nexa-sales-forecasts', 'Forecasts'],
            ['nexa-products-quotes', 'Products & Quotes'],
            'Document', 'Template',
        ]},
        {name: 'nexa-marketing', label: 'Marketing', iconClass: 'fas fa-bullhorn', items: [
            'Campaign', 'TargetList', 'EmailTemplate',
            ['nexa-marketing-contacts', 'Marketing Contacts'],
            ['nexa-marketing-email', 'Marketing Email'],
            ['nexa-forms', 'Forms'],
            ['nexa-landing-pages', 'Landing Pages'],
            ['nexa-content-assets', 'Content & Assets'],
            ['nexa-marketing-events', 'Marketing Events'],
            ['nexa-experiments', 'Experiments'],
        ]},
        {name: 'nexa-automation', label: 'Automation', iconClass: 'fas fa-project-diagram', items: [
            ['nexa-workflows', 'Workflows'],
            ['nexa-customer-journeys', 'Customer Journeys'],
            ['nexa-scoring-lifecycle', 'Scoring & Lifecycle'],
            ['nexa-personalization', 'Personalization'],
            ['nexa-target-accounts', 'Target Accounts & ABM'],
        ]},
        {name: 'nexa-service', label: 'Service', iconClass: 'fas fa-headset', items: [
            'Case', 'Email', 'KnowledgeBaseArticle',
            ['nexa-shared-inbox', 'Shared Inbox'],
            ['nexa-service-queues', 'Queues & SLAs'],
            ['nexa-customer-portal', 'Customer Portal'],
        ]},
        {name: 'nexa-channels', label: 'Channels', iconClass: 'fas fa-broadcast-tower', items: [
            ['nexa-live-chat', 'Live Chat'],
            ['nexa-conversational-bots', 'Bots'],
            ['nexa-sms', 'SMS'],
            ['nexa-whatsapp', 'WhatsApp'],
            ['nexa-social', 'Social Media'],
            ['nexa-advertising', 'Advertising'],
        ]},
        {name: 'nexa-analytics', label: 'Analytics', iconClass: 'fas fa-chart-bar', items: [
            ['nexa-analytics-dashboards', 'Dashboards'],
            ['nexa-reports', 'Reports'],
            ['nexa-campaign-analytics', 'Campaign Analytics'],
            ['nexa-attribution', 'Attribution'],
            ['nexa-journey-analytics', 'Journey Analytics'],
            ['nexa-website-traffic', 'Website Traffic'],
            ['nexa-email-performance', 'Email Performance'],
            ['nexa-sales-analytics', 'Sales Analytics'],
            ['nexa-service-analytics', 'Service Analytics'],
            ['nexa-customer-analytics', 'Customer Analytics'],
            ['nexa-seo-analytics', 'SEO Analytics'],
        ]},
        {name: 'nexa-data-tools', label: 'Data & Integrations', iconClass: 'fas fa-database', items: [
            ['nexa-tracking-events', 'Tracking & Events'],
            ['nexa-data-quality', 'Data Quality'],
            ['nexa-consent-privacy', 'Consent & Privacy'],
            ['nexa-import-export', 'Import & Export', '#Contact/exportAudit'],
            ['nexa-integrations', 'Integrations'],
            ['nexa-api-webhooks', 'API & Webhooks'],
        ]},
    ];

    const dashboards = [
        {id: 'overview', name: 'Overview', active: true, modules: ['M02', 'M05', 'M06', 'M07', 'M19'], widgets: ['Activities', 'Tasks', 'Customer Growth', 'Open Pipeline', 'Revenue Summary', 'Service Workload', 'Recent Engagement']},
        {id: 'sales', name: 'Sales', active: true, modules: ['M05', 'M06', 'M19'], widgets: ['Leads', 'Deals by Stage', 'Pipeline Value', 'Forecast', 'Conversion', 'Sales Activity', 'Team Performance']},
        {id: 'marketing', name: 'Marketing', active: false, modules: ['M08', 'M09', 'M10', 'M11', 'M19'], widgets: ['Marketing Contacts', 'Campaign Performance', 'Email Delivery', 'Email Health', 'Conversions', 'Forms', 'Events', 'Asset Comparison']},
        {id: 'automation', name: 'Automation', active: false, modules: ['M12', 'M13', 'M19'], widgets: ['Active Workflows', 'Journey Enrollment', 'Completion', 'Failures', 'Score Movement', 'Automation Outcomes']},
        {id: 'service', name: 'Service', active: false, modules: ['M07', 'M14', 'M19'], widgets: ['Open Cases', 'SLA Status', 'Response Time', 'Resolution Time', 'Satisfaction', 'Queue Workload', 'Knowledge Usage']},
        {id: 'channels', name: 'Channels', active: false, modules: ['M14', 'M15', 'M16', 'M17', 'M19'], widgets: ['Inbox Volume', 'Chat Outcomes', 'Bot Outcomes', 'SMS Delivery', 'WhatsApp Delivery', 'Social Engagement', 'Advertising Conversion']},
        {id: 'customer', name: 'Customer', active: false, modules: ['M05', 'M11', 'M13', 'M19'], widgets: ['Lifecycle Distribution', 'Engagement', 'Retention', 'Account Health', 'Customer Value', 'Timeline Activity']},
        {id: 'analytics', name: 'Analytics', active: false, modules: ['M18', 'M19'], widgets: ['Custom Reports', 'Funnels', 'Journey Analytics', 'Attribution', 'Website Traffic', 'SEO', 'Governed Comparisons']},
    ];

    return Object.freeze({navigation, dashboards});
});
