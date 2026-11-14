# Forms

Forms are built here and placed in a page, post, card or module with `[form:slug]`. Every site starts with a **Contact** form and a **Newsletter** form (which just collects email addresses).

![The Forms screen, listing each form with its shortcode, fields and number of submissions](https://raw.githubusercontent.com/DaleFranks/ramasite/main/docs/screenshots/forms.png)

## Building a form

- **Name** and **Slug**: the slug is used in the shortcode. While a page, post, card or module places the form, it can't be deleted and its slug can't change; the message says where it's used, so those can be changed first.
- **Button wording**: the text on the send button, such as "Send Message".
- **Thank-you message**: shown in place of the form once it has been sent.
- **Email submissions to**: one or more addresses that each submission is emailed to. Leave it empty to only keep submissions in the admin. How email is sent is set on the [Email](email.md) screen.
- **Fields**: add as many as you need, and drag them into order. Each has:
  - a **label** and a **type**: Short text, Long text, Email address, Phone number, Number, Date, Dropdown, Checkboxes or Radio buttons
  - **choices**, for dropdowns, checkboxes and radio buttons
  - optional **help text** shown under the field
  - whether it's **required**

File uploads aren't offered.

![The form editor, with the name, slug, button wording, thank-you message, addresses to email and the fields](https://raw.githubusercontent.com/DaleFranks/ramasite/main/docs/screenshots/form-editor.png)

## Submissions

Everything sent with a form is stored, whether or not the email went out. Open a form to see its **submissions**: each one's answers, when it was sent, and whether it was emailed. **Download CSV** gives a spreadsheet of them all. The newest submissions from every form are also on the Dashboard.

## Spam

Every form checks for spam robots in three ways: a hidden field that only robots fill in, a form sent less than 3 seconds after it was shown, and a form posted without running its small script. A robot caught by any of these is shown the thank-you message, but nothing is kept or emailed. A visitor can also send the same form at most 5 times a minute.

Because of the script check, a form can only be sent with JavaScript turned on. Visitors without it see a short note saying so on the form.

### Sites behind Cloudflare

The limit of 5 a minute counts visitors by their internet address. Behind Cloudflare, or a similar service that sits in front of the site, every visit arrives from one of the service's own addresses, so visitors who reach the site through the same Cloudflare server count as one visitor. On a busy site, a real visitor could occasionally be told "You've sent this form several times in a row" and need to wait a minute. The site doesn't read the visitor's own address from Cloudflare's headers, because on a site that isn't behind Cloudflare those headers could be faked to get round the limit. On most sites the limit is never reached.
