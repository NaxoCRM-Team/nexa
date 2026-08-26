# Nexa Unified CRM and Marketing Automation Specification

## Purpose

This is the canonical detailed functional specification for Nexa. It combines the operational CRM foundation with marketing automation, customer engagement, analytics, integration and extensibility requirements in one connected product.

The specification is implemented alongside the 86 advanced capabilities and 70 non-functional SaaS requirements in the [Product Requirements Inventory](feature-inventory.md). Ownership and delivery mapping are maintained in the [Requirements Traceability Matrix](requirements-traceability.md), while dependency order and release gates are defined in the [Module and Build Roadmap](module-build-roadmap.md).

## Product Invariant

Nexa is one tenant-aware customer platform, not a collection of disconnected applications. CRM records, website behavior, marketing engagement, sales activity, support history, consent, scoring, campaigns and external events must resolve to the same governed customer identity and chronological history.

Every implementation must preserve:

- mandatory tenant and service scope;
- shared identity and relationship contracts;
- permissions, consent and audit controls;
- versioned APIs, events and migrations;
- observable failure and recovery behavior;
- responsive and accessible user workflows.

## Detailed Requirements
Build a single CRM platform combining:
1. Relationship-centred CRM capabilities
2. Sales and business operations
3. Customer support
4. Behavioural tracking
5. Marketing automation
6. Lead scoring
7. Segmentation
8. Campaign automation
9. Email marketing
10. Forms and landing pages
11. Analytics
12. Integrations and APIs
The platform must operate as one unified system. Customer records, sales records, marketing behaviour, campaigns, activities and support history must be connected.

## 1. CORE SYSTEM FOUNDATION
Implement:
- Multi-tenant architecture
- User registration
- Authentication
- Login/logout
- Password reset
- Two-factor authentication
- User profiles
- Organizations
- Companies
- Teams
- Departments
- Roles
- Permissions
- Record-level access control
- Field-level access control where practical
- Audit logs
- Activity logs
- Notifications
- In-app notifications
- Email notifications
- File storage
- File attachments
- Global search
- Advanced filtering
- Sorting
- Pagination
- Import
- Export
- Bulk actions
- Data validation
- Error logging
- Background jobs
- Scheduled tasks
- API rate limiting
- Database backup compatibility
- Responsive web interface

## 2. UNIFIED CUSTOMER RECORD
Every customer must have a complete unified profile.
The profile must combine:
- Identity
- Contact information
- Company information
- Sales information
- Marketing information
- Behavioural information
- Support information
- Activities
- Communication history
- Campaign history
- Lead score
- Segments
- Customer stage
- Opportunities
- Purchases
- Documents
- Notes
- Custom data
The customer timeline must display:
- Website visits
- Pages viewed
- Emails sent
- Emails opened
- Links clicked
- Forms submitted
- Assets downloaded
- Calls
- Meetings
- Tasks
- Notes
- Opportunities
- Support cases
- Campaign actions
- Score changes
- Segment changes
- External events

## 3. CORE CRM ENTITIES
Implement the following:
- Contacts
- Accounts/Companies
- Leads
- Opportunities
- Cases/Support Tickets
- Activities
- Tasks
- Calls
- Meetings
- Emails
- Notes
- Documents
- Projects
- Products
- Custom entities

## 4. CONTACTS
Fields must include:
- First name
- Middle name
- Last name
- Email
- Multiple email addresses
- Phone
- Multiple phone numbers
- Job title
- Department
- Company
- Address
- City
- State
- Country
- Website
- Social profiles
- Contact source
- Lifecycle stage
- Lead status
- Marketing status
- Lead score
- Customer stage
- Owner
- Team
- Tags
- Segments
- Description
- Custom fields
- Consent/preferences
- Created date
- Updated date
- First activity
- Last activity
- Last website visit
- Last email interaction
- Next activity
Support associations with:
- Companies
- Leads
- Opportunities
- Cases
- Activities
- Documents
- Projects
- Campaigns
- Segments
- Custom entities

## 5. ACCOUNTS / COMPANIES
Implement:
- Company name
- Industry
- Website
- Phone
- Email
- Address
- City
- State
- Country
- Revenue
- Number of employees
- Company type
- Parent company
- Subsidiaries
- Owner
- Team
- Tags
- Segments
- Lead score
- Custom fields
- Notes
Support associations with:
- Contacts
- Leads
- Opportunities
- Cases
- Activities
- Documents
- Projects
- Campaigns
- Custom entities

## 6. LEADS
Implement:
- Lead name
- First name
- Last name
- Email
- Phone
- Company
- Source
- Status
- Rating
- Lead score
- Lifecycle stage
- Owner
- Team
- Description
- Custom fields
- Activities
- Marketing behaviour
- Campaign history
- Notes
- Documents
Lead conversion must support conversion into:
- Contact
- Account/company
- Opportunity
The conversion process must preserve:
- Activity history
- Website behaviour
- Email history
- Campaign history
- Lead score
- Source
- Forms submitted
- Segments

## 7. OPPORTUNITIES
Implement:
- Opportunity name
- Account
- Contact
- Amount
- Currency
- Sales stage
- Probability
- Expected close date
- Pipeline
- Owner
- Team
- Products
- Activities
- Notes
- Documents
- Source
- Marketing attribution
- Lost reason
- Custom fields
Support:
- Multiple sales pipelines
- Configurable stages
- Stage probabilities
- Stage-specific required fields
- Stage automation
- Forecasting
- Win/loss tracking

## 8. CASES / SUPPORT TICKETS
Implement:
- Case number
- Subject
- Description
- Customer
- Contact
- Account
- Priority
- Status
- Category
- Assigned agent
- Assigned team
- SLA
- Due date
- Related opportunity
- Related product
- Internal notes
- Customer communication
- Resolution
- Resolution date
Support:
- Ticket assignment
- Ticket queues
- Escalation
- SLA timers
- Status history
- Email-to-ticket
- Web-to-ticket
- Form-to-ticket
- Customer portal access

## 9. ACTIVITY MANAGEMENT
Implement:
- Tasks
- Calls
- Meetings
- Emails
- Notes
- Reminders
Every activity must be associable with:
- Contact
- Account
- Lead
- Opportunity
- Case
- Campaign
- Project
- Custom entity
Implement:
- Activity timeline
- Activity history
- Upcoming activities
- Overdue activities
- Recurring activities
- Activity reminders
- Activity ownership
- Activity assignment
- Team activities

## 10. CALENDAR
Implement:
- Personal calendar
- Team calendar
- Shared calendar
- Meetings
- Calls
- Tasks
- Recurring events
- Reminders
- Time zones
- Working hours
- Holiday configuration
- Calendar synchronization capability

## 11. EMAIL SYSTEM
Implement:
- External email account connection
- SMTP support
- IMAP support where applicable
- Send email from CRM
- Receive email
- Email threading
- Email history
- Email-to-record association
- Email templates
- Email signatures
- Email scheduling
- Email tracking
- Bounce handling
- Unsubscribe management
- Bulk email capability
- Email attachments
Track:
- Sent
- Delivered
- Opened
- Clicked
- Bounced
- Failed
- Unsubscribed

## 12. ANONYMOUS VISITOR TRACKING
Implement:
- Anonymous visitor ID
- Website tracking
- Page view tracking
- Event tracking
- Visitor history
When an anonymous visitor becomes known:
- Match visitor to contact
- Merge historical activity
- Preserve event history
- Prevent duplicate contacts
Example:
Anonymous visitor:
- Visited homepage
- Visited pricing page
- Downloaded document
Visitor submits email.
The system must associate the previous behaviour with the newly identified contact.

## 13. EVENT TRACKING
Track:
- Page views
- Landing page views
- Button clicks
- Form submissions
- Email opens
- Email clicks
- Asset downloads
- Video interactions
- Webinar registration
- Webinar attendance
- Purchases
- Custom API events
External applications must be able to submit custom events.

## 14. CUSTOMER BEHAVIOUR TIMELINE
Display chronological behaviour including:
- Website visits
- Pages viewed
- Emails sent
- Emails opened
- Links clicked
- Forms submitted
- Assets downloaded
- Campaign actions
- Score changes
- Segment changes
- Sales activities
- Support activities
- External events

## 15. SEGMENTATION ENGINE
Implement static and dynamic segments.
Static Segments
Support manual membership.
Dynamic Segments
Automatically calculate membership using conditions.
Conditions must support:
- Contact fields
- Company fields
- Tags
- Lead score
- Lifecycle stage
- Customer stage
- Email activity
- Website activity
- Page visits
- Form submissions
- Campaign activity
- Date conditions
- Last activity
- Location
- Source
- Opportunity stage
- Support status
Example:
IF:
- Country = Nigeria
- AND last activity < 30 days
- AND pricing page visited = Yes
THEN:
- Add to Hot Nigerian Prospects segment.
Segments must automatically update when contact data or behaviour changes.

## 16. LEAD-SCORING ENGINE
Implement positive and negative scoring.
Example:
- Open email: +1
- Click email: +3
- Visit website: +1
- Visit pricing page: +10
- Download asset: +5
- Submit demo form: +20
- Unsubscribe: -20
- No activity for 30 days: -10
Support:
- Multiple scoring models
- Contact scoring
- Company scoring
- Positive points
- Negative points
- Score expiration
- Score thresholds
- Manual score adjustment
- Automated score actions
Example:
- 0-20: Cold
- 21-50: Engaged
- 51-80: Marketing Qualified
- 81+: Sales Ready
Score changes must be recorded in the customer's timeline.

## 17. LIFECYCLE AND CUSTOMER STAGES
Implement configurable stages such as:
- Anonymous Visitor
- Known Contact
- Lead
- Engaged Lead
- Marketing Qualified Lead
- Sales Qualified Lead
- Opportunity
- Customer
- Repeat Customer
- Advocate
- Inactive
- Lost
Stages must support:
- Manual changes
- Automatic changes
- Workflow triggers
- Reporting
- Analytics

## 18. CAMPAIGN BUILDER
Build a visual campaign workflow builder.
Support:
Triggers
- Contact joins segment
- Form submitted
- Page visited
- Asset downloaded
- Email opened
- Link clicked
- Score reached
- Lifecycle stage changed
- Opportunity stage changed
- Date reached
- API event received
Conditions
- Contact field
- Company field
- Score
- Segment membership
- Email activity
- Website activity
- Campaign activity
- Opportunity status
- Customer status
Decisions
- Yes/no branches
- Multiple branches
- A/B paths
- Conditional branches
Actions
- Send email
- Add to segment
- Remove from segment
- Increase score
- Decrease score
- Change stage
- Create task
- Create opportunity
- Create support ticket
- Assign owner
- Send webhook
- Push contact to external CRM
- Send notification
Timing
- Wait minutes
- Wait hours
- Wait days
- Wait until date
- Wait until condition
- Business hours
- Time zones

## 19. EMAIL MARKETING
Implement:
- Email templates
- Drag-and-drop email builder
- HTML editor
- Personalization
- Dynamic content
- Email scheduling
- Batch sending
- Transactional emails
- Campaign emails
- A/B testing
- Unsubscribe management
- Bounce handling
- Delivery tracking
- Open tracking
- Click tracking
Personalization tokens must support:
- First name
- Last name
- Company
- Job title
- Lead score
- Lifecycle stage
- Custom fields

## 20. DYNAMIC CONTENT
Support conditional content.
Example:
IF Industry = Real Estate
 THEN show Real Estate content.
IF Segment = Enterprise
 THEN show Enterprise content.
IF Customer = Existing Customer
 THEN show Customer content.
Dynamic content must work with:
- Emails
- Landing pages
- Forms
- Website content
- Pop-ups
- Calls to action

## 21. FORM BUILDER
Implement drag-and-drop form builder.
Field types:
- Text
- Email
- Phone
- Number
- Dropdown
- Radio
- Checkbox
- Hidden field
- Consent field
- Custom field
Form actions:
- Create contact
- Update contact
- Create lead
- Add to segment
- Remove from segment
- Start campaign
- Send email
- Create task
- Create opportunity
- Create support ticket
- Send notification
- Trigger webhook
- Redirect visitor
Support:
- Progressive profiling
- Validation
- Consent collection
- Conditional fields

## 22. LANDING PAGE BUILDER
Implement:
- Templates
- Drag-and-drop sections
- Text blocks
- Images
- Videos
- Buttons
- Forms
- Custom HTML
- SEO fields
- Tracking
- Analytics

## 23. ASSET MANAGEMENT
Support:
- PDFs
- Documents
- Images
- Videos
- E-books
- Downloads
Track:
- Who downloaded
- When downloaded
- Number of downloads
- Campaign source
- Behaviour after download

## 24. WEBSITE PERSONALIZATION
Implement:
- Dynamic content
- Pop-ups
- Slide-ins
- Notification bars
- Calls to action
- Behaviour-triggered displays
Rules:
IF visitor belongs to Segment A
 THEN show Content A.
IF visitor belongs to Segment B
 THEN show Content B.

## 25. AUTOMATION ENGINE
Implement a unified automation system.
Triggers:
- Record created
- Record updated
- Field changed
- Date reached
- Activity completed
- Stage changed
- Contact enters segment
- Contact leaves segment
- Form submitted
- Email opened
- Email clicked
- Page visited
- Score threshold reached
- External webhook received
Actions:
- Create record
- Update record
- Send email
- Create task
- Notify user
- Notify team
- Add tag
- Remove tag
- Change owner
- Change status
- Change lifecycle stage
- Change lead score
- Add to segment
- Remove from segment
- Create opportunity
- Create case
- Send webhook
- Update associated record
Example:
WHEN Opportunity becomes Won:
1. Create customer
2. Change lifecycle stage to Customer
3. Send welcome email
4. Create onboarding task
5. Create onboarding project
6. Assign customer success manager
7. Notify account manager
8. Add customer to onboarding segment
9. Start customer onboarding campaign

## 26. CUSTOMER SUPPORT AUTOMATION
Implement:
IF:
- Customer submits support form
THEN:
- Create ticket
- Assign support team
- Send confirmation email
- Start SLA timer
- Notify assigned agent
IF:
- Ticket remains unresolved for configured period
THEN:
- Escalate ticket
- Notify manager
- Update priority

## 27. SALES AUTOMATION
Implement:
IF:
- Lead score reaches Sales Ready threshold
THEN:
- Change lifecycle stage
- Create sales task
- Assign sales representative
- Notify sales team
- Create opportunity where configured
IF:
- Contact visits pricing page multiple times
THEN:
- Increase score
- Notify sales representative
- Add to high-intent segment

## 28. MARKETING ANALYTICS
Implement:
Email Analytics
- Sent
- Delivered
- Opened
- Clicked
- Bounced
- Unsubscribed
Website Analytics
- Visitors
- Page views
- Returning visitors
- Conversion rate
- Traffic sources
- Campaign sources
- Top pages
- Visitor behaviour
Lead Analytics
- Lead score
- Score changes
- Marketing-qualified leads
- Sales-qualified leads
- Engagement trends
- Most active contacts

## 29. SALES ANALYTICS
Implement:
- Leads by source
- Leads by owner
- Conversion rate
- Opportunities by stage
- Pipeline value
- Win rate
- Lost reasons
- Average sales cycle
- Revenue forecast
- Sales representative performance
- Marketing-to-sales conversion

## 30. CUSTOMER ANALYTICS
Implement:
- New customers
- Active customers
- Inactive customers
- Customer value
- Customer activity
- Customer engagement
- Customer retention
- Customer lifecycle
- Repeat customers

## 31. SUPPORT ANALYTICS
Implement:
- Open tickets
- Resolved tickets
- Average resolution time
- SLA breaches
- Cases by agent
- Cases by category
- Customer satisfaction
- Support volume
- First response time

## 32. CAMPAIGN ANALYTICS
Implement:
- Contacts entered
- Contacts completed
- Contacts exited
- Drop-off points
- Branch performance
- Conversion rate
- Campaign performance
- Revenue generated

## 33. ATTRIBUTION
Track the customer journey:
- Advertisement
- Website visit
- Landing page
- Form submission
- Asset download
- Email campaign
- Demo request
- Sales opportunity
- Purchase
Support:
- First-touch attribution
- Last-touch attribution
- Multi-touch attribution where practical

## 34. CUSTOMIZATION ENGINE
Administrators must be able to create custom entities.
Examples:
- Property
- Vehicle
- Student
- Patient
- Membership
- Contract
- Supplier
- Project
- Application
Support custom fields:
- Text
- Long text
- Number
- Currency
- Percentage
- Date
- Date/time
- Boolean
- Dropdown
- Multi-select
- Radio
- Email
- Phone
- URL
- Address
- Image
- File
- Relationship
Support:
- Custom layouts
- Custom tabs
- Custom menus
- Custom relationships
- Custom views
- Custom filters
- Custom dashboards

## 35. RELATIONSHIP ENGINE
Support:
- One-to-one relationships
- One-to-many relationships
- Many-to-many relationships
- Parent-child relationships
- Custom relationship labels
Examples:
- Contact works for Company
- Contact is Decision Maker for Company
- Contact owns Property
- Company employs Contact
- Customer owns Vehicle
- Contact is associated with Opportunity
- Contact belongs to Campaign
- Contact belongs to Segment

## 36. DYNAMIC LOGIC
Implement conditional field behaviour.
Examples:
IF Customer Type = Corporate
 THEN show Company Registration Number.
IF Opportunity Stage = Won
 THEN require Contract Number.
IF Priority = Urgent
 THEN notify Manager.
Support:
- Conditional field visibility
- Conditional required fields
- Conditional sections
- Conditional automation
- Conditional permissions where practical

## 37. FORMULA ENGINE
Support calculated fields.
Examples:
- Quantity x Unit Price = Total
- Today - Last Activity = Days Since Contact
- Deal Value x Probability = Forecast Value
Support:
- Mathematical formulas
- Date formulas
- Text formulas
- Conditional formulas
- Record-based formulas where practical

## 38. REPORTING ENGINE
Users must be able to:
- Select object
- Select properties
- Apply filters
- Group records
- Count records
- Sum values
- Calculate averages
- Calculate conversion
- Create charts
- Create tables
- Create funnels
- Create KPI reports
Reports must support data from:
- Contacts
- Companies
- Leads
- Opportunities
- Cases
- Activities
- Campaigns
- Emails
- Website events
- Segments
- Custom entities

## 39. DASHBOARDS
Implement:
- Personal dashboards
- Team dashboards
- Department dashboards
- Company dashboards
- Marketing dashboards
- Sales dashboards
- Support dashboards
- Custom dashboards
Widgets:
- KPI cards
- Tables
- Bar charts
- Line charts
- Pie charts
- Funnels
- Pipelines
- Conversion reports
- Activity feeds

## 40. API
Build REST API endpoints for:
- Contacts
- Accounts
- Companies
- Leads
- Opportunities
- Cases
- Activities
- Campaigns
- Segments
- Events
- Forms
- Landing pages
- Emails
- Assets
- Scores
- Stages
- Reports
- Custom entities
Support:
- Create
- Read
- Update
- Delete
- Search
- Filter
- Pagination
- Bulk operations
- Associations
- Event submission
- Webhooks

## 41. WEBHOOKS
Support:
- Record created
- Record updated
- Record deleted
- Stage changed
- Opportunity won
- Case created
- Contact enters segment
- Lead score threshold reached
- Payment received
- Custom events
Allow external systems to receive event notifications.

## 42. INTEGRATION ENGINE
Design integration capability for:
- Email
- Google Calendar and Google Contacts
- Microsoft Outlook Calendar and Outlook Contacts
- Personal and team calendar systems
- Marketing automation platforms
- Payment systems
- SMS systems
- WhatsApp providers
- Social media systems
- Accounting systems
- E-commerce systems
- Other CRMs
- Custom applications

Calendar and contact connectors must support user-authorized OAuth connections, encrypted credentials, configurable one-way or two-way synchronization, tenant-safe field mapping, duplicate protection, conflict resolution, webhooks or scheduled reconciliation, retry history and safe disconnect behavior.

## 43. DATA MANAGEMENT
Implement:
- Import
- Export
- CSV support
- Bulk operations
- Duplicate detection
- Duplicate merging
- Data validation
- Data transformation
- Data synchronization
- Data history
- Data deletion
- Data export

## 44. COMPLIANCE AND CONSENT
Implement:
- Consent tracking
- Consent history
- Communication preferences
- Opt-in status
- Opt-out status
- Unsubscribe management
- Suppression lists
- Data deletion
- Data export
- Audit history

## 45. SECURITY
Implement:
- Secure authentication
- Password hashing
- Session security
- Two-factor authentication
- Role-based access
- Team-based access
- Record-level permissions
- Audit logs
- API authentication
- Rate limiting
- Input validation
- Secure file handling
- Backup compatibility

## 46. REQUIRED DEVELOPMENT PRINCIPLE
The platform must not be built as disconnected modules.
The following must work as one connected system:
Website Behaviour
       |
Anonymous Visitor
       |
Known Contact
       |
Lead
       |
Marketing Automation
       |
Lead Score
       |
Sales Qualification
       |
Opportunity
       |
Customer
       |
Support
       |
Retention
       |
Repeat Purchase

All stages must share the same customer record and history.

## 47. DELIVERABLE
Deliver:
- Fully functional unified CRM
- Contact management
- Company management
- Lead management
- Opportunity management
- Sales pipelines
- Customer support
- Activity management
- Calendar
- Email system
- Website visitor tracking
- Behaviour tracking
- Segmentation
- Lead scoring
- Campaign automation
- Email marketing
- Form builder
- Landing page builder
- Dynamic content
- Asset management
- Workflow automation
- Marketing analytics
- Sales analytics
- Customer analytics
- Support analytics
- Attribution
- Custom entities
- Custom fields
- Relationship engine
- Formula engine
- Reporting engine
- Dashboard system
- REST API
- Webhooks
- Integration framework
- Database schema
- Source code
- API documentation
- Deployment documentation
- Admin documentation
- User documentation
- Automated tests
- Security documentation

## Requirement Control

Every bullet in this specification is mandatory unless a later architecture decision explicitly replaces it with an equivalent outcome. Before implementation, each deliverable must be linked to a stable requirement ID, module, phase, acceptance criteria, plan entitlement, data impact and automated-test evidence in the traceability matrix and GitHub backlog.

Existing application behavior may be retained, extended, replaced or retired, but retained behavior is not considered complete until it satisfies Nexa tenancy, permissions, UX, API, audit and operational requirements.
