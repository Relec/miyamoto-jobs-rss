# Miyamoto International Jobs RSS

This repo generates a static RSS 2.0 feed from the Miyamoto International UKG/UltiPro job board and writes it to `public/jobs.xml`.

Job board source:

https://mymiyamoto.rec.pro.ukg.net/MIY1500MOTO/JobBoard/9849ea93-8614-4ac2-a14a-aad11b39ba5f/

## Run Locally

```bash
npm install
npm run generate
```

The generated feed will be written to:

```txt
public/jobs.xml
```

## GitHub Action

The workflow at `.github/workflows/generate-feed.yml`:

- Runs every 6 hours.
- Can be run manually with `workflow_dispatch`.
- Installs Node dependencies.
- Runs `npm run generate`.
- Commits `public/jobs.xml` back to the repo if it changed.

It uses GitHub's built-in `GITHUB_TOKEN` through `actions/checkout` and sets:

```yaml
permissions:
  contents: write
```

## GitHub Pages Setup

1. Push this repo to GitHub.
2. Go to the repo settings.
3. Open **Pages**.
4. Under **Build and deployment**, choose **GitHub Actions**.
5. Run the **Generate jobs RSS feed** workflow manually once from the **Actions** tab, or wait for the next scheduled run.

The feed should become available at:

```txt
https://USERNAME.github.io/REPO/jobs.xml
```

Replace `USERNAME` with the GitHub user or organization name and `REPO` with the repository name.

## WordPress Setup

1. Edit the WordPress Careers page.
2. Add the native WordPress RSS block.
3. Paste the GitHub Pages feed URL:

   ```txt
   https://USERNAME.github.io/REPO/jobs.xml
   ```

4. Set the RSS block display options for title, date, and excerpt.
5. Publish or update the page.

An RSS plugin can use the same feed URL if you need more display controls.

## Validate The Feed

After GitHub Pages is enabled, open the feed URL in a browser:

```txt
https://USERNAME.github.io/REPO/jobs.xml
```

You can also paste the URL into an RSS validator such as:

https://validator.w3.org/feed/

## Maintenance Notes

This repo keeps the generated feed at `public/jobs.xml` and deploys the `public` folder through GitHub Actions Pages. GitHub's branch-based Pages source only supports `/` or `/docs`, so the workflow deploy is the reliable low-maintenance option for publishing `/public`.

The generator posts to this UKG endpoint:

```txt
https://mymiyamoto.rec.pro.ukg.net/MIY1500MOTO/JobBoard/9849ea93-8614-4ac2-a14a-aad11b39ba5f/JobBoardView/LoadSearchResults
```

If UKG changes its API, update `UKG_SEARCH_URL` in `src/generate-feed.js` or set it as an environment variable in GitHub Actions.

The detail links currently use this URL pattern:

```txt
https://mymiyamoto.rec.pro.ukg.net/MIY1500MOTO/JobBoard/9849ea93-8614-4ac2-a14a-aad11b39ba5f/OpportunityDetail?opportunityId=OPPORTUNITY_ID
```

That pattern was verified against the live job board while this generator was created.

## Error Handling

- Network failures exit with an error and do not write `public/jobs.xml`.
- Invalid JSON exits with an error and prints a small response sample.
- Unexpected response shapes exit with an error, response keys, and a small safe sample.
- If the UKG request succeeds but returns zero jobs, the script writes a valid empty RSS feed and logs a warning.
