/**
 * BuildPusher Analytics, a Looker Studio community connector.
 *
 * Install: in Apps Script (script.google.com) create a project, paste this file as Code.gs and appsscript.json as the
 * manifest (Project Settings → Show "appsscript.json"), then Deploy → New deployment → Add-on / Community connector
 * type, and open the deployment's Looker Studio link. Sign in with an API token that has the analytics:read scope
 * (Account → API tokens in BuildPusher).
 *
 * It reads GET /api/v1/analytics/sites/{site}/rows: daily totals per day and value of one breakdown.
 */
var cc = DataStudioApp.createCommunityConnector();
var DEFAULT_BASE_URL = 'https://buildpusher.com';
var DIMENSIONS = {
  all: 'Whole site',
  path: 'Page',
  source: 'Source',
  channel: 'Channel',
  campaign: 'Campaign',
  country: 'Country',
  city: 'City',
  device: 'Device',
  browser: 'Browser',
  operating_system: 'Operating system',
  screen_size: 'Screen size'
};

/** Sign in with a BuildPusher API token. */
function getAuthType() {
  return cc.newAuthTypeResponse().setAuthType(cc.AuthType.KEY).setHelpUrl(DEFAULT_BASE_URL + '/help/share-analytics').build();
}

/** Check the stored token. */
function isAuthValid() {
  var token = PropertiesService.getUserProperties().getProperty('buildpusher.token');
  return !!token;
}

/** Store the token Looker Studio asked for. */
function setCredentials(request) {
  PropertiesService.getUserProperties().setProperty('buildpusher.token', request.key);
  return { errorCode: 'NONE' };
}

/** Forget the token. */
function resetAuth() {
  PropertiesService.getUserProperties().deleteProperty('buildpusher.token');
}

/** Only admins see debugging errors. */
function isAdminUser() {
  return false;
}

/** Ask which site and which breakdown. */
function getConfig() {
  var config = cc.getConfig();
  config.newInfo().setId('help').setText('Pick the site and what each row breaks down by. The site ID is in the address of the site’s page in BuildPusher.');
  config.newTextInput().setId('site').setName('Site ID').setPlaceholder('12');
  var select = config.newSelectSingle().setId('dimension').setName('Break down by').setAllowOverride(true);
  Object.keys(DIMENSIONS).forEach(function (key) {
    select.addOption(config.newOptionBuilder().setLabel(DIMENSIONS[key]).setValue(key));
  });
  config.setDateRangeRequired(true);
  return config.build();
}

/** The fields: the date, the breakdown's value, and the day's totals. */
function fields() {
  var f = cc.getFields();
  var types = cc.FieldType;
  f.newDimension().setId('date').setName('Date').setType(types.YEAR_MONTH_DAY);
  f.newDimension().setId('value').setName('Value').setType(types.TEXT);
  ['pageviews', 'visits', 'visitors', 'conversions', 'converted_visits', 'bounces', 'bounce_eligible'].forEach(function (id) {
    f.newMetric().setId(id).setName(id.replace(/_/g, ' ').replace(/^./, function (c) { return c.toUpperCase(); })).setType(types.NUMBER).setAggregation(cc.AggregationType.SUM);
  });
  f.newMetric().setId('bounce_rate').setName('Bounce rate').setType(types.PERCENT).setFormula('SUM($bounces) / SUM($bounce_eligible)');
  f.newMetric().setId('conversion_rate').setName('Conversion rate').setType(types.PERCENT).setFormula('SUM($converted_visits) / SUM($visits)');
  return f;
}

/** Describe the fields to Looker Studio. */
function getSchema() {
  return { schema: fields().build() };
}

/** Fetch the rows for the requested dates, every page, and return the requested fields. */
function getData(request) {
  var token = PropertiesService.getUserProperties().getProperty('buildpusher.token');
  var params = request.configParams || {};
  var base = DEFAULT_BASE_URL;
  var site = String(params.site || '').replace(/[^0-9]/g, '');
  if (!site) {
    cc.newUserError().setText('Enter the site ID in the connector’s settings.').throwException();
  }
  var requested = fields().forIds(request.fields.map(function (field) { return field.name; }));
  var rows = [];
  for (var page = 1; ; page++) {
    var url = base + '/api/v1/analytics/sites/' + site + '/rows?from=' + request.dateRange.startDate + '&to=' + request.dateRange.endDate + '&dimension=' + encodeURIComponent(params.dimension || 'all') + '&page=' + page;
    var response = UrlFetchApp.fetch(url, { headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' }, muteHttpExceptions: true });
    var body = JSON.parse(response.getContentText() || '{}');
    if (response.getResponseCode() >= 400) {
      cc.newUserError().setText('BuildPusher answered: ' + (body.message || response.getResponseCode())).throwException();
    }
    (body.data || []).forEach(function (row) {
      rows.push({ values: requested.asArray().map(function (field) {
        var id = field.getId();
        return id === 'date' ? row.date.replace(/-/g, '') : (row[id] === undefined ? null : row[id]);
      }) });
    });
    if (!body.meta || !body.meta.next_page) break;
  }
  return { schema: requested.build(), rows: rows };
}
