import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const SITE_URL =
  'https://mymiyamoto.rec.pro.ukg.net/MIY1500MOTO/JobBoard/9849ea93-8614-4ac2-a14a-aad11b39ba5f/';

// If UKG changes its API, update UKG_SEARCH_URL or set the UKG_SEARCH_URL env var.
const UKG_SEARCH_URL =
  process.env.UKG_SEARCH_URL ??
  `${SITE_URL.replace(/\/$/, '')}/JobBoardView/LoadSearchResults`;

const SITE_ORIGIN = new URL(SITE_URL).origin;
const FEED_OUTPUT_PATH = path.resolve(
  process.cwd(),
  process.env.FEED_OUTPUT_PATH ?? 'public/jobs.xml',
);
const FEED_URL = process.env.FEED_URL ?? 'jobs.xml';
const UKG_NAMESPACE_URL = 'https://relec.github.io/miyamoto-jobs-rss/ukg';
const PAGE_SIZE = positiveIntegerFromEnv('UKG_PAGE_SIZE', 50);
const MAX_JOBS = positiveIntegerFromEnv('UKG_MAX_JOBS', 500);
const BROWSER_USER_AGENT =
  process.env.UKG_USER_AGENT ??
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

const DOCUMENT_HEADERS = {
  Accept:
    'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
  'Accept-Language': 'en-US,en;q=0.9',
  'Cache-Control': 'no-cache',
  Pragma: 'no-cache',
  'Sec-Fetch-Dest': 'document',
  'Sec-Fetch-Mode': 'navigate',
  'Sec-Fetch-Site': 'none',
  'Sec-Fetch-User': '?1',
  'Upgrade-Insecure-Requests': '1',
  'User-Agent': BROWSER_USER_AGENT,
};

const API_HEADERS = {
  'Content-Type': 'application/json',
  Accept: 'application/json, text/plain, */*',
  'Accept-Language': 'en-US,en;q=0.9',
  'Cache-Control': 'no-cache',
  Origin: SITE_ORIGIN,
  Pragma: 'no-cache',
  Referer: SITE_URL,
  'Sec-Fetch-Dest': 'empty',
  'Sec-Fetch-Mode': 'cors',
  'Sec-Fetch-Site': 'same-origin',
  'User-Agent': BROWSER_USER_AGENT,
  'X-Requested-With': 'XMLHttpRequest',
};

function positiveIntegerFromEnv(name, fallback) {
  const value = Number.parseInt(process.env[name] ?? '', 10);
  return Number.isInteger(value) && value > 0 ? value : fallback;
}

function buildSearchPayload(skip) {
  return {
    opportunitySearch: {
      Top: PAGE_SIZE,
      Skip: skip,
      QueryString: '',
      OrderBy: [
        {
          Value: 'postedDateDesc',
          PropertyName: 'PostedDate',
          Ascending: false,
        },
      ],
      Filters: [],
    },
    matchCriteria: {
      PreferredJobs: [],
      Educations: [],
      LicenseAndCertifications: [],
      Skills: [],
      hasNoLicenses: false,
      SkippedSkills: [],
    },
  };
}

function escapeXml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&apos;');
}

function safeSample(value) {
  const text =
    typeof value === 'string' ? value : JSON.stringify(value, null, 2);

  return text
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 1200);
}

function getResponseKeys(data) {
  if (data && typeof data === 'object' && !Array.isArray(data)) {
    return Object.keys(data).join(', ') || '(none)';
  }

  return Array.isArray(data) ? '(array response)' : `(${typeof data} response)`;
}

function getSetCookieHeaders(headers) {
  if (typeof headers.getSetCookie === 'function') {
    return headers.getSetCookie();
  }

  const header = headers.get('set-cookie');
  if (!header) {
    return [];
  }

  return header.split(/,(?=\s*[^;,=]+=[^;,]+)/);
}

function cookieHeaderFromSetCookie(setCookieHeaders) {
  return setCookieHeaders
    .map((cookie) => cookie.split(';')[0]?.trim())
    .filter(Boolean)
    .join('; ');
}

function parseSearchResponse(responseText, status, statusText, context) {
  if (status < 200 || status >= 300) {
    throw new Error(
      `${context} failed with ${status} ${statusText}. Response sample: ${safeSample(
        responseText,
      )}`,
    );
  }

  let data;
  try {
    data = JSON.parse(responseText);
  } catch (error) {
    throw new Error(
      `${context} returned invalid JSON: ${error.message}. Response sample: ${safeSample(
        responseText,
      )}`,
    );
  }

  if (!data || !Array.isArray(data.opportunities)) {
    throw new Error(
      `Unexpected UKG response shape. Expected an "opportunities" array. Response keys: ${getResponseKeys(
        data,
      )}. Response sample: ${safeSample(data)}`,
    );
  }

  const parsedTotalCount = Number(data.totalCount);

  return {
    opportunities: data.opportunities,
    totalCount: Number.isFinite(parsedTotalCount)
      ? parsedTotalCount
      : data.opportunities.length,
  };
}

async function fetchSessionCookie() {
  let response;

  try {
    response = await fetch(SITE_URL, {
      headers: DOCUMENT_HEADERS,
    });
  } catch (error) {
    throw new Error(`Network request to UKG job board failed: ${error.message}`);
  }

  if (!response.ok) {
    throw new Error(
      `UKG job board request failed with ${response.status} ${response.statusText}. Response sample: ${safeSample(
        await response.text(),
      )}`,
    );
  }

  return cookieHeaderFromSetCookie(getSetCookieHeaders(response.headers));
}

async function fetchSearchResults(skip, sessionCookie) {
  let response;
  const headers = { ...API_HEADERS };

  if (sessionCookie) {
    headers.Cookie = sessionCookie;
  }

  try {
    response = await fetch(UKG_SEARCH_URL, {
      method: 'POST',
      headers,
      body: JSON.stringify(buildSearchPayload(skip)),
    });
  } catch (error) {
    throw new Error(`Network request to UKG failed: ${error.message}`);
  }

  const responseText = await response.text();

  return parseSearchResponse(
    responseText,
    response.status,
    response.statusText,
    'UKG request',
  );
}

async function fetchAllOpportunitiesPaged(fetchPage) {
  const allOpportunities = [];
  let totalCount = null;

  while (allOpportunities.length < MAX_JOBS) {
    const { opportunities, totalCount: reportedTotal } =
      await fetchPage(allOpportunities.length);

    if (totalCount === null) {
      totalCount = reportedTotal;
    }

    allOpportunities.push(...opportunities);

    if (
      opportunities.length === 0 ||
      allOpportunities.length >= totalCount ||
      opportunities.length < PAGE_SIZE
    ) {
      break;
    }
  }

  return allOpportunities.slice(0, MAX_JOBS);
}

async function fetchAllOpportunitiesWithHttp() {
  let sessionCookie = '';

  try {
    sessionCookie = await fetchSessionCookie();
  } catch (error) {
    console.warn(
      `Warning: UKG job board warmup failed, trying direct search request. ${error.message}`,
    );
  }

  return fetchAllOpportunitiesPaged((skip) =>
    fetchSearchResults(skip, sessionCookie),
  );
}

async function fetchSearchResultsInBrowser(page, skip) {
  const result = await page.evaluate(
    async ({ url, payload }) => {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json, text/plain, */*',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(payload),
        credentials: 'include',
      });

      return {
        status: response.status,
        statusText: response.statusText,
        text: await response.text(),
      };
    },
    {
      url: UKG_SEARCH_URL,
      payload: buildSearchPayload(skip),
    },
  );

  return parseSearchResponse(
    result.text,
    result.status,
    result.statusText,
    'UKG browser request',
  );
}

async function fetchAllOpportunitiesWithBrowser() {
  const { chromium } = await import('playwright');
  const browser = await chromium.launch({ headless: true });

  try {
    const context = await browser.newContext({
      userAgent: BROWSER_USER_AGENT,
      locale: 'en-US',
      extraHTTPHeaders: {
        'Accept-Language': 'en-US,en;q=0.9',
      },
    });
    const page = await context.newPage();
    const response = await page.goto(SITE_URL, {
      waitUntil: 'domcontentloaded',
      timeout: 45000,
    });

    if (!response || !response.ok()) {
      throw new Error(
        `UKG browser page request failed with ${response?.status() ?? 'no'} ${
          response?.statusText() ?? 'response'
        }. Page sample: ${safeSample(await page.content())}`,
      );
    }

    return fetchAllOpportunitiesPaged((skip) =>
      fetchSearchResultsInBrowser(page, skip),
    );
  } finally {
    await browser.close();
  }
}

async function fetchAllOpportunities() {
  const errors = [];

  try {
    console.log('Fetching UKG jobs with HTTP strategy.');
    return await fetchAllOpportunitiesWithHttp();
  } catch (error) {
    errors.push(error);
    console.warn(`HTTP strategy failed: ${error.message}`);
  }

  try {
    console.log('Fetching UKG jobs with browser strategy.');
    return await fetchAllOpportunitiesWithBrowser();
  } catch (error) {
    errors.push(error);
  }

  throw new Error(
    `All UKG fetch strategies failed:\n${errors
      .map((error, index) => `${index + 1}. ${error.message}`)
      .join('\n')}`,
  );
}

function firstString(...values) {
  return values.find((value) => typeof value === 'string' && value.trim());
}

function formatLocation(location) {
  if (!location || typeof location !== 'object') {
    return '';
  }

  const address = location.Address ?? {};
  const city = firstString(address.City);
  const state = firstString(address.State?.Code, address.State?.Name);
  const country = firstString(address.Country?.Name, address.Country?.Code);
  const addressParts = [city, state, country].filter(Boolean);

  if (addressParts.length > 0) {
    return addressParts.join(', ');
  }

  return (
    firstString(
      location.LocalizedDescription,
      location.LocalizedName,
      location.Name,
      location.DisplayName,
    ) ?? ''
  );
}

function formatLocations(opportunity) {
  const locations = Array.isArray(opportunity.Locations)
    ? opportunity.Locations
    : [];
  const formattedLocations = locations
    .map(formatLocation)
    .filter(Boolean);

  return [...new Set(formattedLocations)].join('; ');
}

function parseDate(value) {
  if (!value) {
    return null;
  }

  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

function formatJobLocationType(value) {
  if (value === null || value === undefined || value === '') {
    return '';
  }

  return String(value);
}

function opportunityDetailUrl(opportunityId) {
  return `${SITE_URL.replace(/\/$/, '')}/OpportunityDetail?opportunityId=${encodeURIComponent(
    opportunityId,
  )}`;
}

function buildDescription({ location, postedDate, briefDescription }) {
  const parts = [];

  if (location) {
    parts.push(`Location: ${location}`);
  }

  if (postedDate) {
    parts.push(`Posted: ${postedDate.toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      timeZone: 'UTC',
    })}`);
  }

  if (briefDescription) {
    parts.push(briefDescription);
  }

  return parts.join('\n\n');
}

function buildRssItem(opportunity) {
  const id = firstString(opportunity.Id, opportunity.OpportunityId);
  const title = firstString(opportunity.Title, opportunity.JobTitle, 'Untitled job');

  if (!id) {
    throw new Error(
      `Opportunity is missing a stable ID. Opportunity sample: ${safeSample(
        opportunity,
      )}`,
    );
  }

  const location = formatLocations(opportunity);
  const postedDate = parseDate(opportunity.PostedDate);
  const briefDescription =
    firstString(opportunity.BriefDescription, opportunity.Description) ?? '';
  const link = opportunityDetailUrl(id);
  const itemTitle = location ? `${title} - ${location}` : title;
  const description = buildDescription({
    location,
    postedDate,
    briefDescription,
  });
  const category = firstString(opportunity.JobCategoryName);
  const jobLocationType = formatJobLocationType(opportunity.JobLocationType);

  return [
    '    <item>',
    `      <title>${escapeXml(itemTitle)}</title>`,
    `      <link>${escapeXml(link)}</link>`,
    `      <guid isPermaLink="false">${escapeXml(`miyamoto-job-${id}`)}</guid>`,
    postedDate ? `      <pubDate>${postedDate.toUTCString()}</pubDate>` : null,
    category ? `      <category>${escapeXml(category)}</category>` : null,
    location ? `      <ukg:location>${escapeXml(location)}</ukg:location>` : null,
    jobLocationType
      ? `      <ukg:jobLocationType>${escapeXml(jobLocationType)}</ukg:jobLocationType>`
      : null,
    briefDescription
      ? `      <ukg:briefDescription>${escapeXml(briefDescription)}</ukg:briefDescription>`
      : null,
    description
      ? `      <description>${escapeXml(description)}</description>`
      : null,
    '    </item>',
  ]
    .filter(Boolean)
    .join('\n');
}

function buildRssFeed(opportunities) {
  const now = new Date();
  const items = opportunities.map(buildRssItem).join('\n');

  return `<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:ukg="${escapeXml(UKG_NAMESPACE_URL)}">
  <channel>
    <title>Miyamoto International Jobs</title>
    <description>Current job openings from Miyamoto International</description>
    <link>${escapeXml(SITE_URL)}</link>
    <atom:link href="${escapeXml(FEED_URL)}" rel="self" type="application/rss+xml"/>
    <language>en-us</language>
    <lastBuildDate>${now.toUTCString()}</lastBuildDate>
    <generator>miyamoto-jobs-rss</generator>
    <ttl>360</ttl>
${items}
  </channel>
</rss>
`;
}

async function main() {
  const opportunities = await fetchAllOpportunities();

  if (opportunities.length === 0) {
    console.warn(
      'Warning: UKG request succeeded, but no jobs were returned. Writing a valid empty RSS feed.',
    );
  }

  const feed = buildRssFeed(opportunities);
  await mkdir(path.dirname(FEED_OUTPUT_PATH), { recursive: true });
  await writeFile(FEED_OUTPUT_PATH, feed, 'utf8');

  console.log(
    `Generated ${FEED_OUTPUT_PATH} with ${opportunities.length} job item(s).`,
  );
}

main().catch((error) => {
  console.error('Failed to generate Miyamoto jobs RSS feed.');
  console.error(error.stack ?? error.message);
  process.exitCode = 1;
});
