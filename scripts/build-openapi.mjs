// Hand-maintained V2 contract composed with the frozen V1 contract.
// Run: node scripts/build-openapi.mjs (also executed and checked by Docker).
import fs from 'node:fs';
const spec = JSON.parse(fs.readFileSync(new URL('../docs/openapi-v1.json', import.meta.url), 'utf8'));
const S = spec.components.schemas;
const ref = name => ({$ref: '#/components/schemas/' + name});
const str = (maxLength = 120, example) => ({type: 'string', maxLength, ...(example === undefined ? {} : {example})});
const int = (minimum = 0) => ({type: 'integer', minimum});
const num = (nullable = false) => ({type: 'number', nullable});
const date = (nullable = false) => ({type: 'string', format: 'date-time', nullable});
const bool = {type: 'boolean'};
const en = values => ({type: 'string', enum: values});
const arr = items => ({type: 'array', items});
const obj = (properties, required = Object.keys(properties)) => ({type: 'object', properties, ...(required.length ? {required} : {})});
const envelope = schema => obj({data: schema});
const page = name => obj({data: arr(ref(name)), links: ref('PageLinks'), meta: ref('PageMeta')});
const optional = schema => ({...schema, required: undefined});
const json = schema => ({'application/json': {schema}});
const id = int(1);
const roles = ['owner', 'admin', 'member', 'viewer'];
const scopes = ['monitors:read','monitors:write','incidents:read','incidents:write','maintenance:read','maintenance:write','analytics:read','status-pages:read','status-pages:write','notifications:read','notifications:write'];
const events = ['incident.opened','incident.resolved','monitor.degraded','monitor.recovered'];
const message = obj({message: str(500, 'Operation completed.')});
const orgPath = '/api/v2/organizations/{organization}';
spec.info = {
    title: 'Sentinel API', version: '2.0.0', license: {name: 'MIT'},
    description: 'HTTP monitoring and incident response for teams.\n\nRegister or log in, paste data.token into Authorize, list your organizations, then use an organization ID to create a monitor and request a check. Personal tokens expire after seven days. Organization API keys use the same Bearer header and explicit scopes; keys cannot manage accounts, organizations, members or keys.\n\nRoles: owner/admin manage configuration; member operates monitors, incidents and maintenance; viewer reads operational data. Only owners transfer ownership or manage admins. Notification channels and keys are restricted to owner/admin. V1 routes remain available in the personal organization.\n\nResponses use data; paginated collections add links and meta (page ≥ 1, per_page 1–100, default 20). Errors use message and validation errors add errors. Rate limits: 120/minute per user or organization key group; authentication 10/minute/IP; manual checks 6/minute/organization; public status 60/minute/IP. Quotas return 409. All dates use UTC.\n\nChecks use DNS validation, connection pinning, verified TLS, no redirects and no proxies. Only public HTTP:80 and HTTPS:443 targets are accepted. Webhooks require HTTPS. Maintenance keeps check history but suppresses transitions and alerts; consecutive streaks restart after maintenance. Notification delivery is asynchronous and at least once, with a stable Idempotency-Key.'
};
spec.tags = [
 ['Authentication','Personal access tokens and account identity.'],
 ['Organizations','Tenant boundaries and ownership.'],
 ['Members','Membership, roles and one-time invitations.'],
 ['API Keys','Scoped credentials for organization automation.'],
 ['Monitors','HTTP targets, schedules and health thresholds.'],
 ['Checks','Queued checks and immutable execution history.'],
 ['Incidents','Automatic outages, acknowledgement, notes and timelines.'],
 ['Maintenance','One-time windows with explicit monitor selection.'],
 ['Notifications','Encrypted channels and auditable delivery attempts.'],
 ['Analytics','Sample availability, latency, downtime and SLO budgets.'],
 ['Status Pages','Explicitly published components and public health.'],
 ['Operations','Liveness, readiness and API documentation.']
].map(([name, description]) => ({name,description}));
spec.components.securitySchemes.bearerAuth.description = 'Paste a personal Sanctum token or an organization API key (snl_…). Do not include the Bearer prefix. Keys require each endpoint’s explicit scope. Authorization is not persisted between page reloads.';
for (const [code, description] of Object.entries({
 401:'Missing, invalid, expired or revoked credential.',
 403:'Insufficient organization role or API key scope; personal token required for administration.',
 404:'Resource not found, not published, or outside the current organization.',
 409:'Quota reached or state conflict (paused monitor, owner removal, started maintenance, resolved incident, duplicate invitation).',
 410:'Invitation expired.'
})) spec.components.responses[code] = {description, content: json(ref('Error'))};
S.Error.example = {message: 'Resource not found.'};
S.ValidationError.example = {message: 'The name field is required.', errors: {name: ['The name field is required.']}};
const sloTarget = {type:'number',minimum:90,maximum:99.999,default:99.9,example:99.9,description:'Sample-based uptime objective.'};
for (const name of ['MonitorInput','MonitorPatch','Monitor']) S[name].properties.slo_target = sloTarget;
S.Monitor.properties.organization_id = id;
S.MonitorInput.properties.interval_seconds.description += ' The configured MIN_CHECK_INTERVAL_SECONDS may impose a higher minimum, including when omitted.';
S.Check.properties.in_maintenance = bool;
Object.assign(S.Incident.properties,{first_failed_at:date(true),acknowledged_by:{...id,nullable:true},acknowledged_by_key:{...id,nullable:true},acknowledged_at:date(true),duration_seconds:int()});
S.OrganizationInput = obj({name:str(120,'Platform Engineering'),description:{...str(1000,'Customer-facing services.'),nullable:true},retention_days:{...int(1),maximum:365,default:30,description:'Cannot exceed this instance’s MAX_RETENTION_DAYS (default 90).'}},['name']);
S.OrganizationPatch = optional(S.OrganizationInput);
S.Organization = obj({id,name:str(),description:{...str(1000),nullable:true},owner_id:id,is_personal:bool,retention_days:int(1),created_at:date()});
S.Member = obj({id,name:str(),email:{type:'string',format:'email'},role:en(roles)});
S.InvitationInput = obj({email:{type:'string',format:'email',maxLength:255,example:'colleague@example.com'},role:{...en(roles.slice(1)),example:'member'}});
S.Invitation = obj({id,organization_id:id,email:{type:'string',format:'email'},role:en(roles.slice(1)),expires_at:date(),created_at:date(),updated_at:date()});
S.InvitationCreated = obj({...S.Invitation.properties,token:{type:'string',minLength:64,maxLength:64,description:'Shown once. Share securely with the invited user; only a hash is stored. Expires in seven days.'}});
S.ApiKeyInput = obj({name:str(120,'Deployment automation'),scopes:{...arr(en(scopes)),minItems:1,maxItems:11,uniqueItems:true,example:['monitors:read','monitors:write','incidents:read']},expires_at:{...date(true),description:'Optional future expiration, at most one year from now. Omit/null for no expiration.'}},['name','scopes']);
S.ApiKey = obj({id,organization_id:id,name:str(),scopes:arr(en(scopes)),last_used_at:date(true),expires_at:date(true),revoked_at:date(true),created_at:date(),updated_at:date()});
S.ApiKeyCreated = obj({...S.ApiKey.properties,secret:{type:'string',pattern:'^snl_[a-f0-9]{64}$',description:'Shown once. Store securely. Only the SHA-256 hash is persisted.'}});
S.IncidentEvent = obj({id,incident_id:id,user_id:{...id,nullable:true},api_key_id:{...id,nullable:true},type:en(['incident.opened','incident.resolved','acknowledged','note','recovery_started','recovery_interrupted']),message:str(2000),occurred_at:date()});
S.TimelineEntry = obj({id,type:en(['check','incident.opened','incident.resolved','acknowledged','note','recovery_started','recovery_interrupted']),message:{...str(2000),nullable:true},user_id:{...id,nullable:true},api_key_id:{...id,nullable:true},occurred_at:date(),http_status_code:{...int(100),nullable:true},check_status:{...en(['success','failure','timeout']),nullable:true},in_maintenance:{type:'boolean',nullable:true}});
S.MaintenanceInput = obj({description:str(1000,'Scheduled database upgrade'),start_at:{...date(),description:'Now or a future time. At most one minute in the past.'},end_at:{...date(),description:'After start_at and no more than 90 days from now.'},monitor_ids:{...arr(id),minItems:1,maxItems:50,uniqueItems:true,example:[1]}});
S.Maintenance = obj({id,description:str(1000),start_at:date(),end_at:date(),status:en(['scheduled','active','completed']),monitor_ids:arr(id)});
S.ChannelInput = obj({name:str(120,'Primary on-call'),type:en(['webhook','slack','discord']),endpoint:{type:'string',format:'uri',maxLength:2048,writeOnly:true,example:'https://hooks.example.com/sentinel',description:'Public HTTPS:443 only. Slack: hooks.slack.com/services/…; Discord: discord.com/api/webhooks/…. Validated on save and execution; encrypted at rest, never returned.'},signing_secret:{type:'string',minLength:32,maxLength:128,nullable:true,writeOnly:true,description:'Optional HMAC secret, encrypted at rest. Signature: sha256=HMAC-SHA256(timestamp + "." + exact JSON bytes).'},events:{...arr(en(events)),minItems:1,maxItems:4,uniqueItems:true,example:['incident.opened','incident.resolved']},is_active:{...bool,default:true}},['name','type','endpoint','events']);
S.ChannelPatch = optional(S.ChannelInput);
S.Channel = obj({id,name:str(),type:en(['webhook','slack','discord']),events:arr(en(events)),is_active:bool,has_signing_secret:bool,created_at:date()});
S.NotificationPayload = obj({event:en(events),occurred_at:date(),monitor:obj({id,name:str(),status:en(['unknown','healthy','degraded','down'])}),incident_id:{...id,nullable:true}});
S.Delivery = obj({id,public_id:{type:'string',format:'uuid'},organization_id:id,notification_channel_id:id,monitor_id:id,event:en(events),execution_id:{type:'string',format:'uuid'},payload:ref('NotificationPayload'),status:en(['pending','retrying','delivered','failed','cancelled']),attempts:int(),next_attempt_at:date(),delivered_at:date(true),created_at:date(),updated_at:date()});
S.DeliveryAttempt = obj({id,notification_delivery_id:id,attempt:int(1),http_status:{...int(100),nullable:true},error_type:{...en(['http_status','transport','unsafe_target']),nullable:true},duration_ms:int(),attempted_at:date()});
S.Analytics = obj({start_at:date(),end_at:date(),total_checks:int(),eligible_checks:int(),maintenance_checks:int(),failed_checks:int(),uptime_percentage:num(true),average_latency_ms:num(true),latency_ms:obj({p50:num(true),p95:num(true),p99:num(true)}),incident_count:int(),total_downtime_seconds:int(),mttr_seconds:num(true),history_available_from:date(),partial_retention:bool,slo:obj({target_uptime:num(),current_uptime:num(true),error_budget_checks:num(),error_budget_used_checks:int(),error_budget_remaining_checks:num(true),error_budget_remaining_percentage:num(true)})});
S.Analytics.example = {start_at:'2026-10-03T00:00:00.000000Z',end_at:'2026-10-04T00:00:00.000000Z',total_checks:1440,eligible_checks:1440,maintenance_checks:0,failed_checks:1,uptime_percentage:99.93056,average_latency_ms:82.6,latency_ms:{p50:75,p95:125,p99:180},incident_count:0,total_downtime_seconds:0,mttr_seconds:null,history_available_from:'2026-09-04T00:00:00.000000Z',partial_retention:false,slo:{target_uptime:99.9,current_uptime:99.93056,error_budget_checks:1.44,error_budget_used_checks:1,error_budget_remaining_checks:0.44,error_budget_remaining_percentage:30.55556}};
S.StatusPageInput = obj({name:str(120,'Sentinel Status'),slug:{type:'string',minLength:3,maxLength:80,pattern:'^[a-z0-9]+(?:-[a-z0-9]+)*$',example:'sentinel-status'},description:{...str(1000,'Availability of our public services.'),nullable:true},is_published:{...bool,default:false},components:{...arr(obj({monitor_id:id,name:str(120,'Payment API')})),minItems:1,maxItems:10,description:'Explicit public aliases. All monitors must belong to this organization.'}},['name','slug','components']);
S.StatusPagePatch = optional(S.StatusPageInput);
S.StatusPage = obj({id,slug:str(80),name:str(),description:{...str(1000),nullable:true},is_published:bool,public_url:str(200),components:arr(obj({monitor_id:id,name:str()}))});
S.PublicStatus = obj({name:str(),description:{...str(1000),nullable:true},status:en(['unknown','healthy','degraded','down','maintenance']),components:arr(obj({name:str(),status:en(['unknown','healthy','degraded','down','maintenance']),uptime_percentage_24h:num(true)})),incidents:arr(obj({component:str(),status:en(['open','resolved']),started_at:date(),resolved_at:date(true),duration_seconds:int()})),maintenance:arr(obj({start_at:date(),end_at:date(),status:en(['scheduled','active','completed']),components:arr(str())})),updated_at:date()});
S.PublicStatus.example = {name:'Sentinel Status',description:'Public service health',status:'healthy',components:[{name:'Payment API',status:'healthy',uptime_percentage_24h:99.99}],incidents:[],maintenance:[],updated_at:'2026-10-04T12:00:00Z'};
for (const name of ['Organization','Member','Invitation','ApiKey','IncidentEvent','TimelineEntry','Maintenance','Channel','Delivery','DeliveryAttempt','StatusPage']) S[name+'Page'] = page(name);

function operation(path, method, tag, summary, {schema, body, code=200, list=false, scope, personal=false, description='', example, publicAccess=false, extraErrors=[]}={}) {
    const parameters = [...path.matchAll(/\{(\w+)\}/g)].map(([,name]) => ({name,in:'path',required:true,schema:name==='slug'?str(80):id,description:name==='organization'?'Organization ID from GET /api/v2/organizations.':'Resource identifier.'}));
    if(list) parameters.push(...structuredClone(spec.paths['/api/v1/monitors'].get.parameters));
    const responses = {[code]:code===204?{description:'Completed. No response body.'}:{description:code===201?'Created':code===202?'Accepted for asynchronous processing':'Success',content:json(schema?list?ref(schema+'Page'):envelope(ref(schema)):envelope(message))}};
    for(const status of [...(publicAccess?['404','429']:['401','403','404','409','422','429']),...extraErrors]) responses[status] = {$ref:'#/components/responses/'+status};
    const result = {tags:[tag],summary,operationId:method+path.replace(/[^a-zA-Z0-9]/g,'_'),description:[description,scope?'Required API key scope: '+scope+'.':'',personal?'Personal token required; organization API keys cannot access this endpoint.':''].filter(Boolean).join('\n\n'),parameters,responses,...(publicAccess?{security:[]}:{})};
    if(body)result.requestBody={required:true,content:{'application/json':{schema:typeof body==='string'?ref(body):body,...(example?{example}:{})}}};
    spec.paths[path]??={};spec.paths[path][method]=result;
    return result;
}
// Clone the tested V1 monitor/auth contract, updating tenant context and operation IDs.
for(const [path, item] of Object.entries(structuredClone(spec.paths))){
    if(!path.startsWith('/api/v1/')) continue;
    const target=path.includes('/auth/')?path.replace('/v1/','/v2/'):path.replace('/api/v1',orgPath);
    const copy=structuredClone(item);
    for(const [method,op] of Object.entries(copy)){
        if(!['get','post','patch','delete'].includes(method))continue;
        op.operationId='v2_'+op.operationId;
        op.tags=path.endsWith('/checks')||path.endsWith('/check')?['Checks']:op.tags;
        if(!path.includes('/auth/')){
            op.parameters=[{name:'organization',in:'path',required:true,schema:id},...(op.parameters||[])];
            const scope=path.endsWith('/incidents')?'incidents:read':method==='get'?'monitors:read':'monitors:write';
            op.description+=(op.description?'\n\n':'')+'Organization scoped. Required API key scope: '+scope+'. Owner/admin/member may write; viewer may read. Quotas return 409.';
            op.responses['403']={$ref:'#/components/responses/403'};op.responses['409']={$ref:'#/components/responses/409'};
            if(path.endsWith('/check')) op.description+='\n\nSix manual checks/minute/organization, shared by personal tokens, API keys and V1.';
        }
    }
    spec.paths[target]=copy;
    for(const op of Object.values(item)){if(op&&typeof op==='object'&&op.operationId){const original=spec.paths[path][Object.keys(item).find(k=>item[k]===op)];original.deprecated=true;original.description='Compatibility route. Monitors belong to your personal organization; organization API keys require V2.\n\n'+(original.description||'');}}
}
operation('/api/v2/organizations','get','Organizations','List my organizations',{schema:'Organization',list:true,personal:true,description:'Includes the personal organization used by V1 clients.'});
operation('/api/v2/organizations','post','Organizations','Create organization',{schema:'Organization',body:'OrganizationInput',code:201,personal:true,description:'Creator becomes owner. Owned organization quota is enforced under a database lock.'});
operation(orgPath,'get','Organizations','Get organization',{schema:'Organization',personal:true});
operation(orgPath,'patch','Organizations','Update organization',{schema:'Organization',body:'OrganizationPatch',personal:true,description:'Owner/admin. Retention is bounded by the instance maximum.'});
operation(orgPath+'/transfer-ownership','post','Organizations','Transfer ownership',{schema:'Organization',body:obj({user_id:id}),personal:true,description:'Owner only. Target must already be a member and have organization quota. Previous owner becomes admin. Personal organizations cannot transfer.'});
operation(orgPath+'/leave','post','Members','Leave organization',{code:204,personal:true,description:'Owners must transfer ownership before leaving.'});
operation(orgPath+'/members','get','Members','List members',{schema:'Member',list:true,personal:true,description:'Owner/admin only. Includes member email addresses.'});
operation(orgPath+'/members/{member}','patch','Members','Change member role',{body:obj({role:en(roles.slice(1))}),personal:true,description:'Owner/admin. Only owner may manage admins. Owner cannot be demoted through this endpoint.'});
operation(orgPath+'/members/{member}','delete','Members','Remove member',{code:204,personal:true,description:'Owner/admin. Admins cannot remove admins or owner. Organization resources survive member removal.'});
operation(orgPath+'/invitations','get','Members','List invitations',{schema:'Invitation',list:true,personal:true,description:'Owner/admin. Tokens and hashes are never listed.'});
operation(orgPath+'/invitations','post','Members','Invite a member',{schema:'InvitationCreated',body:'InvitationInput',code:201,personal:true,description:'Owner/admin. Only owner may invite admins. Token shown once; share it securely with the recipient. Acceptance requires a personal account with matching email. Pending invitations reserve member quota.'});
operation(orgPath+'/invitations/{invitation}','delete','Members','Cancel invitation',{code:204,personal:true,description:'Owner/admin. Cancelling releases reserved quota.'});
operation('/api/v2/invitations/accept','post','Members','Accept invitation',{body:obj({token:{type:'string',minLength:64,maxLength:64,pattern:'^[a-f0-9]+$'}}),personal:true,description:'Matching email required. Single use, expires after seven days. Additional 10/minute/IP limit shared with authentication.',extraErrors:['410']}).responses['200'].content=json(envelope(obj({organization_id:id,message:str()})));
operation(orgPath+'/api-keys','get','API Keys','List API keys',{schema:'ApiKey',list:true,personal:true,description:'Owner/admin. Includes revoked keys; never exposes the secret or hash.'});
operation(orgPath+'/api-keys','post','API Keys','Create API key',{schema:'ApiKeyCreated',body:'ApiKeyInput',code:201,personal:true,description:'Owner/admin. Secret shown only once with Cache-Control: no-store. Read and write scopes are independent; no wildcard or administrator scope.'});
operation(orgPath+'/api-keys/{apiKey}','delete','API Keys','Revoke API key',{code:204,personal:true,description:'Owner/admin. Revocation is immediate for subsequent requests; key metadata remains available.'});
operation(orgPath+'/incidents','get','Incidents','List organization incidents',{schema:'Incident',list:true,scope:'incidents:read'});
operation(orgPath+'/incidents/{incident}','get','Incidents','Get incident',{schema:'Incident',scope:'incidents:read'});
operation(orgPath+'/incidents/{incident}/acknowledge','post','Incidents','Acknowledge incident',{schema:'Incident',scope:'incidents:write',description:'Owner/admin/member. Idempotent for an open incident. Records either user or API key actor. Does not stop checks or automatic resolution.'});
operation(orgPath+'/incidents/{incident}/notes','post','Incidents','Add incident note',{schema:'IncidentEvent',body:obj({message:str(2000,'Investigating upstream database timeouts.')}),code:201,scope:'incidents:write',description:'Owner/admin/member. Notes remain private to the organization and survive check retention.'});
operation(orgPath+'/incidents/{incident}/timeline','get','Incidents','Read incident timeline',{schema:'TimelineEntry',list:true,scope:'incidents:read',description:'Chronological union of retained checks and human/state events, from first failure through resolution. Includes incident summary and meta.check_history_retained_since. Check rows are not duplicated into the event table.'});
operation(orgPath+'/maintenance','get','Maintenance','List maintenance windows',{schema:'Maintenance',list:true,scope:'maintenance:read'});
operation(orgPath+'/maintenance','post','Maintenance','Schedule maintenance',{schema:'Maintenance',body:'MaintenanceInput',code:201,scope:'maintenance:write',description:'Owner/admin/member. One-time, half-open [start_at, end_at) window. Checks continue; no incident opens, resolves or alerts during maintenance. Existing incidents stay open; streaks reset across maintenance. Analytics excludes flagged checks.'});
operation(orgPath+'/maintenance/{maintenance}','delete','Maintenance','Cancel scheduled maintenance',{code:204,scope:'maintenance:write',description:'Only future windows can be cancelled. Active/completed windows are immutable to preserve historical meaning.'});
operation(orgPath+'/notification-channels','get','Notifications','List notification channels',{schema:'Channel',list:true,scope:'notifications:read',description:'Owner/admin or scoped key. Endpoint and signing secret are write-only.'});
operation(orgPath+'/notification-channels','post','Notifications','Create notification channel',{schema:'Channel',body:'ChannelInput',code:201,scope:'notifications:write',description:'Owner/admin or scoped key. Generic webhook, Slack incoming webhook or Discord webhook. Secrets are encrypted using APP_KEY. Delivery begins on subscribed transitions after creation.'});
operation(orgPath+'/notification-channels/{channel}','patch','Notifications','Update notification channel',{schema:'Channel',body:'ChannelPatch',scope:'notifications:write',description:'Owner/admin or scoped key. Rotate endpoint/secret by submitting a new value. Pending deliveries use current channel configuration. Disable to cancel pending deliveries.'});
operation(orgPath+'/notification-channels/{channel}','delete','Notifications','Delete notification channel',{code:204,scope:'notifications:write',description:'Deletes associated delivery/attempt history. Disable instead to preserve audit history.'});
operation(orgPath+'/notification-deliveries','get','Notifications','List notification deliveries',{schema:'Delivery',list:true,scope:'notifications:read',description:'Durable outbox. Dispatcher runs each minute. Up to five attempts with delays 30s, 120s, 600s, 1800s, plus dispatcher latency. Retry network/408/425/429/5xx; other statuses and blocked targets are terminal. At-least-once delivery; receivers should deduplicate Idempotency-Key.'});
operation(orgPath+'/notification-deliveries/{delivery}/attempts','get','Notifications','Inspect delivery attempts',{schema:'DeliveryAttempt',list:true,scope:'notifications:read',description:'Sanitized status, error type and duration. No response bodies, target URLs or exception messages.'});
const analytics = operation(orgPath+'/monitors/{monitor}/analytics','get','Analytics','Get uptime, latency and SLO',{schema:'Analytics',scope:'analytics:read',description:'Half-open [start_at, end_at), maximum 31 days. Uptime = successful eligible checks / eligible checks. Maintenance checks excluded. Latency uses successful eligible checks, nearest-rank percentiles. Downtime sums incident intervals clipped to the range; incident duration begins when the failure threshold is reached and includes maintenance for already-open incidents. MTTR averages full duration of incidents resolved in range. Sample-based error budget = eligible checks × (1 − target/100); remaining budget is clamped at zero. No samples returns null. Queries aggregate/order in SQL without loading all checks.'});
analytics.parameters.push({name:'period',in:'query',schema:{...en(['24h','7d','30d','custom']),default:'24h'}},{name:'start_at',in:'query',schema:date(),description:'Required with end_at and for period=custom.'},{name:'end_at',in:'query',schema:date(),description:'After start_at and no later than now.'});
operation(orgPath+'/status-pages','get','Status Pages','List status pages',{schema:'StatusPage',list:true,scope:'status-pages:read'});
operation(orgPath+'/status-pages','post','Status Pages','Create status page',{schema:'StatusPage',body:'StatusPageInput',code:201,scope:'status-pages:write',description:'Owner/admin or scoped key. Unpublished by default. Publish only selected monitors with explicit public component names.'});
operation(orgPath+'/status-pages/{statusPage}','get','Status Pages','Get status page configuration',{schema:'StatusPage',scope:'status-pages:read'});
operation(orgPath+'/status-pages/{statusPage}','patch','Status Pages','Update or publish status page',{schema:'StatusPage',body:'StatusPagePatch',scope:'status-pages:write',description:'Owner/admin or scoped key. Unpublishing immediately hides the public endpoints, including warm cache entries.'});
operation(orgPath+'/status-pages/{statusPage}','delete','Status Pages','Delete status page',{code:204,scope:'status-pages:write'});
operation('/api/v2/status/{slug}','get','Status Pages','Read public service status',{schema:'PublicStatus',publicAccess:true,description:'No authentication. Explicit component aliases, current aggregate status, 24h sample uptime, up to 20 recent incidents and 20 maintenance windows. No internal IDs, monitor URLs, notes, membership or secrets. Cache up to 30 seconds. Priority: down > degraded > maintenance > unknown > healthy.'});
operation('/status/{slug}','get','Status Pages','View public status page',{publicAccess:true,description:'Minimal server-rendered HTML. User-provided text is escaped.'}).responses['200']={description:'Public status page',content:{'text/html':{schema:{type:'string'}}}};
for(const path of ['/','/health','/health/ready','/docs','/openapi.json'])for(const op of Object.values(spec.paths[path]))op.tags=['Operations'];
spec.paths['/'].get.description='Technical landing page in HTML; application/json when requested. Readiness is checked separately from liveness.';
spec.paths['/'].get.responses['200'].content['text/html']={schema:{type:'string'}};
S.Root.properties.version.example='2.0.0';S.Root.properties.api.example='/api/v2';
S.Root.properties.readiness={type:'string',example:'/health/ready'};
// Keep the V2 workflow first in each group while retaining discoverable V1 routes.
for (const [path, operations] of Object.entries(spec.paths)) {
    for (const operation of Object.values(operations)) {
        if (operation.tags?.includes('Monitoring')) operation.tags = [path.endsWith('/incidents') ? 'Incidents' : 'Checks'];
    }
}
spec.paths = Object.fromEntries(Object.entries(spec.paths).sort(([a], [b]) => Number(a.startsWith('/api/v1/')) - Number(b.startsWith('/api/v1/'))));
const output=JSON.stringify(spec,null,2)+'\n';
const destination=new URL('../public/openapi.json',import.meta.url);
if(process.argv.includes('--check')){
    if(fs.readFileSync(destination,'utf8')!==output){console.error('OpenAPI is stale. Run node scripts/build-openapi.mjs.');process.exit(1);}
    console.log('OpenAPI source and artifact match.');
}else{fs.writeFileSync(destination,output);console.log('Generated OpenAPI:',Object.keys(spec.paths).length,'paths.');}
