# Email Import Improvements

## Problem

When emails from Gmail/Outlook were imported, they showed as ugly HTML with all the tags visible:

```
Hello<div dir="auto"><br></div><div dir="auto">My website appears to be down...
<span style="font-family:aptos;font-size:16.799999px">This message was created...
```

This made tickets unreadable and unprofessional.

---

## Solution

### 1. Improved HTML to Text Conversion

**What it does:**
- Converts HTML emails to clean, readable plain text
- Removes inline styles, classes, IDs, font tags
- Converts `<div>`, `<p>`, `<br>` to proper line breaks
- Converts links to readable format: `text (url)`
- Decodes HTML entities (like `&nbsp;`, `&quot;`)
- Cleans up excessive whitespace

**Before:**
```html
Hello<div dir="auto"><br></div><div dir="auto">My website with your sister company...
```

**After:**
```
Hello

My website with your sister company appears to be down.
It keeps giving me a 500 error. Can you assist at all with this?

Secondly on cPanel how do I make a mail account?
```

### 2. Email Signature Removal

**Automatically removes:**
- Standard signatures (`--` delimiter)
- Mobile signatures (`Sent from my iPhone`, `Get Outlook for...`)
- Email client signatures
- Underscore separators (`_____`)

**Before:**
```
Thanks for your help!

--
John Smith
CEO, Example Corp
Phone: 555-1234

Sent from my iPhone
```

**After:**
```
Thanks for your help!
```

### 3. Quoted Reply Cleanup

**Removes:**
- Lines starting with `>` (quoted text)
- "On [date], [person] wrote:" chains
- Forwarded message headers
- Email thread history

**Before:**
```
I have a question about my account.

On Jan 5, 2025, support@example.com wrote:
> Thanks for contacting us.
>
> How can we help?
```

**After:**
```
I have a question about my account.
```

---

## Technical Details

### Priority Order

1. **Try plain text part first** - Most emails have both HTML and plain text versions. Plain text is cleaner.
2. **Fall back to HTML** - If no plain text, convert HTML to text
3. **Double-check for HTML** - Sometimes the "plain text" part contains HTML (looking at you, Gmail)

### HTML Conversion Process

```php
convertHtmlToText($html)
├── Remove inline styles/classes/ids
├── Remove font tags
├── Remove span tags (keep content)
├── Convert <div> → newline
├── Convert <p> → double newline
├── Convert <br> → newline
├── Convert <a> → "text (url)"
├── Strip remaining HTML tags
├── Decode HTML entities
├── Clean up excessive line breaks
└── Trim whitespace
```

### Signature Detection

```php
cleanEmailBody($body)
├── Remove signature delimiters (-- )
├── Remove mobile signatures
├── Remove quoted lines (>)
├── Stop at "On X wrote:"
├── Stop at forwarded headers
└── Trim result
```

---

## What Gets Imported Now

### Clean Ticket Content

Your example email would now import as:

```
Hello

My website with your sister company using your shared hosting service appears to be down. It keeps giving me a 500 error. Can you assist at all with this?

Secondly on cPanel how do I make a mail account?

And finally.

When I try to email an outlook mail address it gives me the below error.

This message was created automatically by mail delivery software.

A message that you sent could not be delivered to one or more of its
recipients. This is a permanent error. The following address(es) failed:

jackhjameson@hotmail.com (mailto:jackhjameson@hotmail.com)
host hotmail-com.olc.protection.outlook.com (http://hotmail-com.olc.protection.outlook.com)[52.101.68.13]
SMTP error from remote mail server after pipelined MAIL FROM:<jack.jameson@caminhoit.com> SIZE=2880:
550 5.7.1 Unfortunately, messages from [51.89.254.43] weren't sent. Please contact your Internet service provider since part of their network is on our block list (S3140). You can also refer your provider to http://mail.live.com/mail/troubleshooting.aspx[truncated]
```

Much cleaner and readable! ✅

---

## Benefits

✅ **Readable tickets** - No more HTML tags in ticket content
✅ **Clean formatting** - Proper line breaks and spacing
✅ **No signatures** - Automatic removal of email signatures
✅ **No quoted replies** - Only new content imported
✅ **Better UX** - Staff can read tickets easily
✅ **Professional** - Tickets look clean and organized

---

## Edge Cases Handled

### Gmail HTML Emails
- Converts `<div dir="auto">` to proper line breaks
- Removes inline styles like `font-family:aptos;font-size:16.799999px`
- Handles nested divs and spans

### Outlook Emails
- Removes Outlook-specific HTML attributes
- Handles styled links properly
- Cleans up "Get Outlook for..." signatures

### Mobile Emails
- Removes "Sent from my iPhone/Android"
- Handles mobile email formatting
- Cleans up mobile signatures

### Email Threads
- Stops at "On X wrote:" delimiter
- Removes quoted reply chains
- Only imports new content

---

## Testing

To test the improvements:

1. **Send a test email** from Gmail with formatting
2. **Wait for import** (1 minute)
3. **Check the ticket** - should be clean text
4. **Try with signatures** - should be removed
5. **Try replying** - quoted text should be removed

---

## Files Modified

- `includes/EmailImport.php`
  - `getEmailBody()` - Improved HTML/plain text handling
  - `convertHtmlToText()` - New HTML to text converter
  - `cleanEmailBody()` - New signature/quote remover

---

## Future Enhancements

Possible improvements:
- Detect and preserve important formatting (bold, lists)
- Handle inline images as attachments
- Better thread detection
- Language-specific signature patterns
- Custom signature patterns per user

---

**Last Updated:** November 6, 2025
**Version:** 2.0.0
**Author:** CaminhoIT Development Team
