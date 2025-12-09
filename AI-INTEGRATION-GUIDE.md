# AI-Powered Reply Suggestions - Setup Guide

## Overview

Your support ticket system now includes **AI-powered reply suggestions** using OpenAI's GPT-4o-mini model. Staff can click a button to automatically generate professional, context-aware responses to customer tickets.

---

## Features

- **Intelligent Reply Generation**: AI reads the entire ticket thread and generates professional IT support responses
- **Context-Aware**: Includes ticket details, customer information, priority, and full conversation history
- **Editable Suggestions**: Staff can review and edit AI-generated replies before sending
- **Professional Tone**: Optimized for IT support with friendly, solution-oriented responses
- **Usage Tracking**: Logs AI usage for analytics and monitoring

---

## Setup Instructions

### Step 1: Import Database Migration

In phpMyAdmin, import the SQL file:
```
migrations/add-ai-integration.sql
```

This creates:
- `support_ai_settings` table (AI configuration)
- `support_ai_usage_log` table (usage analytics)

### Step 2: Get OpenAI API Key

1. Go to [https://platform.openai.com](https://platform.openai.com)
2. Sign up or log in to your account
3. Navigate to **API Keys** section
4. Click **"Create new secret key"**
5. Copy the key (starts with `sk-...`)

**Important**: Keep this key secure! Don't share it or commit it to version control.

### Step 3: Configure AI Settings

Run these SQL commands in phpMyAdmin:

```sql
-- Enable AI assistance
UPDATE support_ai_settings
SET setting_value = '1'
WHERE setting_key = 'ai_enabled';

-- Add your OpenAI API key
UPDATE support_ai_settings
SET setting_value = 'sk-your-actual-api-key-here'
WHERE setting_key = 'openai_api_key';
```

**Replace** `sk-your-actual-api-key-here` with your actual API key.

### Step 4: Verify Settings (Optional)

Check your AI settings:
```sql
SELECT * FROM support_ai_settings;
```

You should see:
- `ai_enabled` = `1`
- `openai_api_key` = Your API key
- `ai_model` = `gpt-4o-mini`
- `ai_temperature` = `0.7`
- `ai_max_tokens` = `500`

---

## How to Use

### For Staff Members

1. **Open any ticket** in the staff portal:
   ```
   https://caminhoit.com/operations/staff-view-ticket.php?id=TICKET_ID
   ```

2. **Look for the "AI Suggest Reply" button** above the reply textarea

3. **Click the button** to generate an AI-powered reply
   - The button shows "Generating..." while processing
   - Usually takes 2-5 seconds

4. **Review the suggested reply** in the textarea
   - AI analyzes the entire ticket thread
   - Generates professional, helpful responses
   - Includes context from previous messages

5. **Edit if needed** - You have full control to modify the suggestion

6. **Send the reply** as normal (click "Reply" button)

---

## What the AI Considers

The AI analyzes:
- **Ticket Details**: Subject, priority, status
- **Customer Information**: Name, company
- **Initial Issue**: Original ticket description
- **Full Conversation**: All previous replies (customer and staff)
- **Timestamps**: When messages were sent
- **Context**: IT support best practices

The AI generates replies that:
- Address the customer's specific concerns
- Are solution-oriented and helpful
- Use a friendly but professional tone
- Are concise (2-4 paragraphs)
- Include next steps when applicable
- **Do NOT** include signatures (staff adds their own)

---

## Configuration Options

### Adjust AI Settings

You can customize AI behavior in the database:

**Change AI Model** (if needed):
```sql
UPDATE support_ai_settings
SET setting_value = 'gpt-4o-mini'
WHERE setting_key = 'ai_model';
```

Options: `gpt-4o-mini` (recommended), `gpt-4o`, `gpt-3.5-turbo`

**Adjust Creativity** (0.0 = factual, 1.0 = creative):
```sql
UPDATE support_ai_settings
SET setting_value = '0.7'
WHERE setting_key = 'ai_temperature';
```

**Change Response Length**:
```sql
UPDATE support_ai_settings
SET setting_value = '500'
WHERE setting_key = 'ai_max_tokens';
```

(500 tokens ≈ 375 words)

### Disable AI Temporarily

```sql
UPDATE support_ai_settings
SET setting_value = '0'
WHERE setting_key = 'ai_enabled';
```

---

## Usage Analytics

### View AI Usage Statistics

**Recent AI usage:**
```sql
SELECT
    ticket_id,
    model_used,
    success,
    tokens_used,
    created_at
FROM support_ai_usage_log
ORDER BY created_at DESC
LIMIT 20;
```

**AI usage by ticket:**
```sql
SELECT
    t.id,
    t.subject,
    COUNT(al.id) as ai_requests,
    SUM(al.success) as successful_requests
FROM support_tickets t
LEFT JOIN support_ai_usage_log al ON t.id = al.ticket_id
GROUP BY t.id
ORDER BY ai_requests DESC;
```

**AI success rate:**
```sql
SELECT
    COUNT(*) as total_requests,
    SUM(success) as successful,
    (SUM(success) * 100.0 / COUNT(*)) as success_rate,
    SUM(tokens_used) as total_tokens_used
FROM support_ai_usage_log;
```

---

## Costs and API Usage

### OpenAI Pricing (as of 2025)

**GPT-4o-mini** pricing:
- Input: $0.150 per 1M tokens
- Output: $0.600 per 1M tokens

**Example Cost**:
- Average ticket reply: ~1000 input tokens + 300 output tokens
- Cost per reply: ~$0.00033 (less than 1 cent)
- 1000 replies: ~$0.33

**Note**: Prices may change. Check [OpenAI Pricing](https://openai.com/api/pricing/)

### Monitor Your Usage

Check your OpenAI usage dashboard:
```
https://platform.openai.com/usage
```

---

## Files Created

| File | Purpose |
|------|---------|
| `includes/AIHelper.php` | Core AI integration class |
| `migrations/add-ai-integration.sql` | Database schema for AI |
| `AI-INTEGRATION-GUIDE.md` | This setup guide |

## Files Modified

| File | What Changed |
|------|--------------|
| `operations/staff-view-ticket.php` | Added "AI Suggest Reply" button and AJAX handler |

---

## Troubleshooting

### "AI assistance is not enabled"

**Solution**: Enable AI in the database:
```sql
UPDATE support_ai_settings
SET setting_value = '1'
WHERE setting_key = 'ai_enabled';
```

### "Please configure OpenAI API key"

**Solution**: Add your API key:
```sql
UPDATE support_ai_settings
SET setting_value = 'sk-your-key-here'
WHERE setting_key = 'openai_api_key';
```

### "Failed to generate AI reply"

**Check**:
1. Verify API key is correct
2. Check OpenAI account has credits
3. Review error logs:
   ```sql
   SELECT * FROM support_ai_usage_log
   WHERE success = 0
   ORDER BY created_at DESC;
   ```
4. Check PHP error logs for details

### Button doesn't appear

**Possible causes**:
1. Ticket is closed (AI only works for open tickets)
2. JavaScript error (check browser console)
3. File permissions issue

### AI generates inappropriate responses

**Adjust temperature**:
```sql
-- More conservative (0.5)
UPDATE support_ai_settings
SET setting_value = '0.5'
WHERE setting_key = 'ai_temperature';
```

---

## Security Notes

- API keys are stored in the database
- Only staff members can access AI features
- All AI requests are logged for auditing
- Sensitive customer data is only sent to OpenAI (review their [privacy policy](https://openai.com/policies/privacy-policy))
- Consider data privacy regulations (GDPR, etc.)

---

## Best Practices

1. **Always review AI suggestions** before sending
2. **Edit for accuracy** - AI doesn't know your specific environment
3. **Add personal touches** - Make it sound like you
4. **Use for initial drafts** - Speed up response time
5. **Don't rely 100% on AI** - Use your expertise
6. **Monitor costs** - Check OpenAI usage regularly

---

## Future Enhancements

Potential improvements:
- AI-powered ticket categorization
- Sentiment analysis on customer messages
- Automatic priority detection
- Multi-language support
- Custom AI prompts per category
- AI settings page in admin panel

---

## Example AI Reply

**Ticket**: "My email isn't syncing on my phone"

**AI Generates**:
```
Thank you for reaching out regarding your email sync issue. I understand
how frustrating it can be when your emails aren't updating on your mobile
device.

Let's troubleshoot this step by step:

1. First, please verify you have a stable internet connection (try both
   WiFi and mobile data)
2. Go to your phone's Settings > Mail > Accounts and remove the account
3. Restart your phone completely
4. Re-add the email account with your credentials

If the issue persists after these steps, please let me know:
- Which phone model and OS version you're using
- What email service (Gmail, Outlook, etc.)
- Any error messages you see

I'll be here to help further if needed!
```

**Staff can then**:
- Edit for company-specific details
- Add signature
- Send immediately or modify further

---

## Support

For issues with:
- **AI integration**: Check this guide and error logs
- **OpenAI API**: Visit [OpenAI Help Center](https://help.openai.com)
- **General support system**: See other documentation files

---

**Congratulations!** 🎉 Your support ticket system now has AI-powered reply suggestions.

Staff can save time while maintaining professional, helpful customer service.

---

**Last Updated**: 2025-11-06
**Version**: 1.0.0
**Model**: GPT-4o-mini
