# Cradle

**Build WordPress websites with an AI coding agent, and keep them fully editable.**

You describe the website you want, and optionally add screenshots or a design. Your AI coding agent
(Claude Code, Codex, Cursor…) builds it inside WordPress with real pages, blocks, menus and content
types. Afterwards, you or your client can change everything in the normal WordPress editor.

Cradle is a theme, a set of plugins, and instructions that tell the AI how to work. It does not run AI
inside WordPress and needs no API key or subscription.

---

## What you need

- **A fresh WordPress 7.1 site**, ideally on your own computer (Local, WAMP, XAMPP, MAMP…), with PHP 8.3
  or newer.
- **An AI coding agent** that works with files on your computer, such as Claude Code, Codex or Cursor.
- **The Cradle kit**, `cradle-kit-<date>.zip`, from this repository's **Releases** page.

---

## Build a new website in 5 steps

### Step 1: Install the kit

1. Open your WordPress folder, the one that contains `wp-config.php`.
2. Extract `cradle-kit-<date>.zip` into it. When asked, choose to **merge** the `wp-content` folder.
   You now have:
   ```
   wp-content/
   ├── themes/cradle/          Cradle parent theme (never edit it)
   ├── themes/cradle-child/    YOUR website
   └── plugins/cradle…         Cradle plugins
   ```
3. *Optional:* rename the `cradle-child` folder to your site's name, for example `acme-site`. Do it
   **now**. Renaming it after activation loses the theme's settings.

### Step 2: Activate

1. **Plugins:** activate **Cradle** first. Then activate **Cradle Blocks**, **Cradle Content**, and any
   other Cradle plugin you want (see [The plugins](#the-plugins)).
2. **Appearance → Themes:** activate **Cradle Child**. Don't activate **Cradle**; that's the parent.
3. Open **Cradle → Overview** in the admin menu and check that everything is ready.

### Step 3: Describe your website

Put your material in the child theme folder, `wp-content/themes/cradle-child/`:

| What | Required? | Contents |
| --- | --- | --- |
| `northstar.md` | **Yes** | Your website brief (see below). The file is already there, empty. |
| `design-reference/` | Optional | Create this folder for screenshots, an HTML/CSS design, logo, photos, fonts and texts. |

**How to write `northstar.md`:** use plain words, in any format or language. A good brief covers:

- the site name, what the business does, and who the visitors are
- the pages you need and what goes on each one
- what editors should be able to change later ("add new projects", "change the slider")
- the look: colors, fonts, sites you like, or "match the files in design-reference/"
- menus, forms, languages and anything special
- what "finished" means to you

It doesn't have to be complete, because the AI asks about anything that is missing. For a full checklist,
see [website-brief.md](packages/themes/cradle/docs/website-brief.md).

### Step 4: Open the site in your AI agent

Open the folder `wp-content/themes/cradle-child/` in your coding agent. The agent finds its instructions
there automatically (`AGENTS.md`, or `CLAUDE.md` for Claude Code).

Also allow the agent to read the parent theme and the plugins, because its skills and rules are stored
there. With Claude Code, run this from the child theme folder:

```bash
claude --add-dir ../cradle --add-dir ../../plugins
```

In other agents, allow access to those two folders when asked.

### Step 5: Start prompting

Go one step at a time. Copy these prompts and adjust them:

1. **Set up:** *"This is a new Cradle website called Acme Studio. Set it up for first use."*
2. **Check the brief:** *"Read northstar.md and design-reference/. Tell me what's missing or unclear.
   Don't build anything yet."*
3. **Plan:** *"Make a plan: pages, sections, content types, and what editors can change. Show it to me
   before building."*
4. **Build:** *"Build the website following the plan."*
5. **Check:** *"Compare every page with the design on desktop and mobile, test editing each page, and
   tell me what passed and what didn't."*
6. **Change things.** Talk to it as you would to a developer:
   - *"Make the hero a slider of the three latest projects."*
   - *"Add a Services section that editors can manage."*
   - *"On mobile the footer doesn't match the screenshot. Fix it."*
7. **Continue another day:** *"Continue the website. Where are we, and what's next?"*

The agent writes its progress into the child theme's `docs/` folder. A new chat, or even a different
agent, can therefore continue where the last one stopped.

### What you get

- Real WordPress pages made of blocks. Every text, image, button and menu can be edited in WordPress.
- Repeating content (projects, services, team members…) as proper content types that editors add to.
- Your design, not a generic template. Anything the AI had to guess is written down in its notes.
- No page builders, extra libraries or hidden plugins.

### Good habits

- Build on a local copy, never directly on the live site.
- Put the design in files (`design-reference/`), not only in the chat.
- Review the plan (prompt 3) before the build. Changes cost the least at that point.
- When it's done, try editing the pages yourself.
- Keep your child theme folder in git so you can undo any change.

---

## The plugins

Cradle's screens are in the **Cradle** menu of the WordPress admin. **Cradle** must be active for the
other plugins to work. A plugin changes nothing until you set it up.

### Cradle (required)

The engine that the other plugins use.
**How to use:** **Cradle → Overview** shows whether the theme is set up correctly and which Cradle plugins
are installed, active or broken, with links to activate them. Check this page first when something
doesn't work.

### Cradle Blocks (recommended)

Adds these blocks to the editor:

- **Feature card**: an image, title, text and link.
- **Post cards**: a list of cards showing posts, pages or entries of any content type.
- **Hero slider**: a large slider made of entries, for example the latest three projects or ones you
  pick.

**How to use:** in the editor, click **+**, search for the block's name and add it. Set its options in
the right-hand sidebar.

### Cradle Content (recommended)

Create your own content types (Projects, Services, Team…), with categories and extra fields such as
price, location or date. No code is needed.
**How to use:** go to **Cradle → Content types**.

1. **Post types:** add a type, for example "Projects". It appears in the admin menu.
2. **Taxonomies:** add categories for it if needed, for example "Project types".
3. **Field groups:** add fields and attach them to the type.
4. **Export / import:** copy your content types to another site.

### Cradle Translations

Change the interface text of the site, per language. This covers text from Cradle, WordPress and
WooCommerce, such as buttons, labels and messages. It does not translate your page content.
**How to use:** go to **Cradle → Translations**.

1. Add a language (for example `fr_FR`), or open German or Arabic, which are included.
2. Click **Refresh source strings**, find a text, and type your wording.
3. Your changes are saved as drafts. Publish them to make them live.
4. Under **Import & export**, you can move translations as JSON or `.po`/`.pot` files.

### Cradle Migration

Back up a whole site in one file, or move it to another domain, folder or server.
**How to use:**

1. On the old site, go to **Cradle → Migration** and click **Export site**. Keep the page open; when the
   export is ready, a `.cradle` file downloads by itself. (It is a normal ZIP file with another extension.)
   It includes the language files, so an Arabic or other right-to-left site stays right-to-left.
2. On the new site (a WordPress with Cradle and Cradle Migration active), go to **Cradle → Migration →
   Import**, choose the `.cradle` file and click **Import**. Confirm once.
3. Wait while it uploads, checks the file and prepares the new site. The current site keeps working until
   the final switch, which takes a few seconds.
4. You are taken to the login page. Sign in with an administrator account **of the imported site**.
5. Check the site, then release the **safety hold** under **Jobs & recovery** so email and scheduled tasks
   run again. If something is wrong, use **Roll back** there.

Past exports are listed under **Archives**, with a download link and **Restore…** for each.

### Cradle Admin

Brand the WordPress admin and the login page, for example for a client.
**How to use:** go to **Cradle → Branding & admin**.

- **Branding:** your name, logo, help links, a dashboard welcome box, and the login page design.
- **Appearance:** admin colors, spacing and font size. Color combinations that are hard to read are
  refused.
- **Labels:** rename admin menu items and content type labels.
- **Custom CSS:** extra admin styles (administrators only).

Each section has **Reset to defaults**. If the admin becomes unusable, add
`define( 'CRADLE_ADMIN_SAFE_MODE', true );` to `wp-config.php`.

### Cradle Login

Moves the login page from `/wp-login.php` to an address you choose, such as `/team-login/`. This reduces
bot traffic, but it does not replace strong passwords.
**How to use:** go to **Cradle → Login**.

1. Enter the new address. Choose what visitors see at the old address: a "not found" page or the home
   page.
2. Click **Run health check**, then turn the new address on. It won't turn on if the check fails.
3. Bookmark the new address.

If you get locked out, add `define( 'CRADLE_DISABLE_CUSTOM_LOGIN', true );` to `wp-config.php` and log
in at `/wp-login.php` as usual.

### Cradle Duplicate

Copies a post or page as a new draft.
**How to use:** in the Posts or Pages list, hover over an item and click **Duplicate**. In the editor,
use **Duplicate as new draft**. Settings (which content types, a title suffix, and who becomes the
author) are under **Cradle → Duplicate**.

### Cradle Dummy Content

Fills a site with sample posts for testing, then removes exactly those posts.
**How to use:** go to **Cradle → Dummy content**.

1. **Generate:** choose the content type, how many items, and options such as images, categories and
   fields. Then click **Generate batch**.
2. **Batches & cleanup:** open the batch and move its items to the trash, or delete them for good. Items
   that someone edited or that are in use are kept unless you tick the option to include them. Your
   real content is never touched.

### Cradle Social

Adds links to your social profiles and "share this page" buttons, without tracking scripts.
**How to use:**

1. Go to **Cradle → Social**. Enter your profile addresses, and choose the share services and a style.
2. In the editor, add the **Social profile links** or **Share links** block where you want it, for
   example in the footer. You can also turn on automatic share links for posts.

---

## Keeping Cradle up to date

1. Get the newer `cradle-update-<date>.zip` from **Releases**.
2. Extract it into the WordPress folder as you did with the kit, but this time choose **replace**. It
   contains no child theme, so your website is not affected.
3. Check **Cradle → Overview**.

Never extract the full kit over a working site.

## Going live

- Move the finished site to its server with **Cradle Migration**.
- Your child theme contains working files (the brief, notes and design references). Don't leave them
  readable on a public server. See [docs/deployment.md](docs/deployment.md).
- For emergencies, see [docs/recovery.md](docs/recovery.md).

## Example

`sites/sandbox/themes/cradle-site` is a complete example website ("Harbor & Pine") built this way. It
includes the brief, design files, plan and notes.

## Working on Cradle itself

To change the framework rather than build a website, see [docs/development.md](docs/development.md).

## License

GPL-2.0-or-later
