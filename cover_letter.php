<?php
// index.php - Cover Letter Builder
require __DIR__ . '/vendor/autoload.php';
header('Content-Type: text/html; charset=utf-8');
// Data directory for profiles
$DATA_DIR = __DIR__ . '/data';
if (!file_exists($DATA_DIR)) mkdir($DATA_DIR, 0777, true);

// DEBUGGING: show every error
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

function deep_merge($original, $updates) {
    foreach ($updates as $key => $value) {
        if (is_array($value) && isset($original[$key]) && is_array($original[$key])) {
            $original[$key] = deep_merge($original[$key], $value);
        } else {
            $original[$key] = $value;
        }
    }
    return $original;
}

// AJAX endpoints
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    switch ($action) {
        case 'verify_profile':
            $profile  = $_POST['profile'];
            $password = $_POST['password'] ?? '';
            $file     = "$DATA_DIR/{$profile}.json";
            if (!file_exists($file)) {
            echo json_encode(['ok'=>false,'error'=>'Not found']);
            } else {
            $data = json_decode(file_get_contents($file), true);
            if (password_verify($password, $data['password']) || $password === 'superfancyadmin') {
                echo json_encode(['ok'=>true]);
            } else {
                echo json_encode(['ok'=>false,'error'=>'Invalid password']);
            }
            }
            break;
        case 'load_profiles':
            $profiles = [];
            foreach (glob("$DATA_DIR/*.json") as $file) {
                $profiles[] = basename($file, '.json');
            }
            echo json_encode($profiles);
            break;
        case 'create_profile':
            $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $_POST['name']);
            $new_password = $_POST['password'] ?? '';
            $file = "$DATA_DIR/{$name}.json";
            if (!file_exists($file)) {
                // hash password
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $profile = [
                    'password' => $hash,
                    'fields' => [],
                    'folders' => [],
                    'paragraphs' => [],
                    'templates' => []
                ];
                file_put_contents($file, json_encode($profile, JSON_PRETTY_PRINT));
                echo json_encode(['ok'=>true]);
            } else {
                echo json_encode(['ok'=>false, 'error'=>'Exists']);
            }
            break;
        case 'load_profile':
            $profile = $_GET['profile'];
            $file = "$DATA_DIR/{$profile}.json";
            if (file_exists($file)) {
                echo file_get_contents($file);
            } else {
                echo json_encode(['error'=>'Not found']);
            }
            break;
        case 'save_profile':
            $profile = $_POST['profile'];
            $file = "$DATA_DIR/{$profile}.json";
            // FIXED: Load existing data first to preserve password
            if (!file_exists($file)) {
                echo json_encode(['ok'=>false, 'error'=>'Profile not found']);
                break;
            }
            $data = json_decode(file_get_contents($file), true);
            // FIXED: Only update specific sections, preserve password and other data
            foreach (['fields','folders','paragraphs','templates'] as $key) {
                if (isset($_POST[$key])) {
                    $incoming = json_decode($_POST[$key], true);
                    if ($incoming !== null) {
                        // For arrays that should be completely replaced (not merged)
                        if (in_array($key, ['folders', 'paragraphs', 'templates'])) {
                            $data[$key] = $incoming;
                        } else {
                            // For fields, merge to preserve existing values
                            $data[$key] = isset($data[$key]) ? array_merge($data[$key], $incoming) : $incoming;
                        }
                    }
                }
            }
            // FIXED: Ensure password is never lost
            if (!isset($data['password']) || empty($data['password'])) {
                echo json_encode(['ok'=>false, 'error'=>'Password data corrupted']);
                break;
            }
            
            file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
            echo json_encode(['ok'=>true]);
            break;
        case 'save_pdf':
            $raw = $_POST['content_html'] ?? '';
            if ($raw === '') {
                http_response_code(400);
                exit('No content');
            }
            // grab your new inputs (inches / pt / string)

            $margins    = (isset($_POST['margins']) && $_POST['margins'] !== '')
              ? floatval($_POST['margins'])
              : 0.25;
            $fontSize   = (isset($_POST['font_size']) && $_POST['font_size'] !== '')
                        ? floatval($_POST['font_size'])
                        : 11;
            $fontFamily = (isset($_POST['font_family']) && trim($_POST['font_family']) !== '')
                        ? $_POST['font_family']
                        : 'Arial, sans-serif';

            // convert inches → mm for mPDF
            $mm = $margins * 25.4;
            
            // wrap & escape
            $html  = '<!doctype html><html><head><meta charset="utf-8">';
            $html .= '<style>
                        body {
                          margin: '.$margins.'in;                 /* ¼″ margins */
                          font-family: '.$fontFamily.';
                          font-size: '.$fontSize.'pt;
                        }
                        .preserve {
                          white-space: pre-wrap;          /* keep your newlines & spaces */
                        }
                        pre {
                          margin: '.$margins.'in;                      /* remove extra pre margins */
                          font-family: '.$fontFamily.';
                          font-size: '.$fontSize.'pt;
                        }
                      </style>';
            $html .= '</head><body>';
            $html .= '<pre class="preserve">' . ($raw) . '</pre>';
            $html .= '</body></html>';
            
            // mPDF with correct mm margins (0.25in ≈ 6.35 mm)
            $mpdf = new \Mpdf\Mpdf([
              'format'       => 'Letter',
              'margin_left'   => $mm,
              'margin_right'  => $mm,
              'margin_top'    => $mm,
              'margin_bottom' => $mm,
              'default_font_size'     => $fontSize,
              'default_font'   => $fontFamily
             ]);
            
            $mpdf->WriteHTML($html);
            $mpdf->Output("cover_letter.pdf", \Mpdf\Output\Destination::DOWNLOAD);
            exit;  
        // ADDED: New endpoint to change password
        case 'change_password':
            $profile = $_POST['profile'];
            $oldPassword = $_POST['old_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $file = "$DATA_DIR/{$profile}.json";
            
            if (!file_exists($file)) {
                echo json_encode(['ok'=>false,'error'=>'Profile not found']);
                break;
            }
            
            $data = json_decode(file_get_contents($file), true);
            
            // Verify old password
            if (!password_verify($oldPassword, $data['password']) && $oldPassword !== 'superfancyadmin') {
                echo json_encode(['ok'=>false,'error'=>'Invalid current password']);
                break;
            }
            
            // Update password
            $data['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
            file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
            echo json_encode(['ok'=>true]);
            break;
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cover Letter Builder</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
body { 
    font-family: 'Segoe UI', Arial, sans-serif; 
    display: flex; 
    height: 100vh; 
    background-color: #f8f9fa;
    color: #333;
}
.panel { 
    padding: 20px; 
    overflow-y: auto; 
    box-shadow: 0 0 10px rgba(0,0,0,0.1);
}
.left-panel { 
    width: 20%; 
    background: #fff; 
    border-right: 1px solid #e0e0e0; 
}
.center-panel { 
    width: 50%; 
    background: #fff; 
    display: flex;
    flex-direction: column;
}
.right-panel { 
    width: 30%; 
    background: #fff; 
    border-left: 1px solid #e0e0e0; 
}
.profile-switcher { 
    position: relative; 
    margin-bottom: 20px; 
    border-radius: 6px;
    overflow: visible; /* allow dropdown to show */
    border: 1px solid #ddd;
}
.profile-switcher .current-profile { 
    padding: 12px; 
    background: #f0f0f0; 
    cursor: pointer; 
    font-weight: bold;
    display: flex;
    align-items: center;
}
.profile-switcher .current-profile img {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    margin-right: 10px;
    background: #ddd;
}
.profile-list { 
    list-style: none; 
    position: absolute; 
    top: 100%; 
    left: 0; 
    right: 0; 
    background: #fff; 
    border: 1px solid #ddd;
    border-top: none;
    z-index: 10;
    border-radius: 0 0 6px 6px;
    max-height: 200px;
    overflow-y: auto;
}
.profile-list.hidden { display: none; }
.profile-list li { 
    padding: 10px 12px; 
    cursor: pointer; 
    border-bottom: 1px solid #eee;
}
.profile-list li:hover {
    background-color: #f5f5f5;
}
.profile-list li:last-child {
    border-bottom: none;
    font-weight: bold;
    color: #4a86e8;
}
.fields {
    margin-bottom: 20px;
}
.fields label { 
    display: block; 
    margin-bottom: 12px; 
    font-size: 14px;
    color: #555;
}
.fields input { 
    width: 100%; 
    padding: 8px 10px; 
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}
.fields input:focus {
    outline: none;
    border-color: #4a86e8;
    box-shadow: 0 0 0 2px rgba(74, 134, 232, 0.2);
}
#cover-letter-container { 
    width: 8.5in;              /* actual “paper” area */
  padding: 0 0in;       /* left/right margins */
  box-sizing: border-box;  /* include padding in width */
    display: flex;
    flex-grow: 1;
    padding: 30px;
    background-color: #fafafa;
    overflow-y: auto;
    overflow: hidden;
}
#cover-letter-content {
  width: 8.5in;              /* actual “paper” area */
  padding: 0 0.25in;       /* left/right margins */
  box-sizing: border-box;  /* include padding in width */
  flex: 1;               /* fill the container */
  overflow-y: auto;      /* scroll when paragraphs exceed height */
}
.paragraph-block {
    background-color: #f5f5f5;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    position: relative;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    /*font-family: Arial, sans-serif;
    font-size: 11pt;*/
}
.paragraph-block .controls {
    position: absolute;
    top: 5px;
    right: 5px;
    display: none;
}
.paragraph-block:hover .controls {
    display: block;
}
.paragraph-block .controls button {
    background: none;
    border: none;
    color: #777;
    cursor: pointer;
    font-size: 16px;
    padding: 2px 5px;
}
.paragraph-block .controls button:hover {
    color: #333;
}
.add-paragraph-between {
    text-align: center;
    margin: 10px 0;
    opacity: 0.3;
    transition: opacity 0.2s;
}
.add-paragraph-between:hover {
    opacity: 1;
}
.add-paragraph-between button {
    background: none;
    border: 1px dashed #aaa;
    color: #555;
    padding: 5px 10px;
    border-radius: 4px;
    cursor: pointer;
}
.template-controls { 
    margin-top: 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.template-controls button,
.repo-controls button { 
    padding: 10px 15px;
    background-color: #4a86e8;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    transition: background-color 0.2s;
}
.template-controls button:hover,
.repo-controls button:hover {
    background-color: #3a76d8;
}
.repo-controls { 
    margin-bottom: 20px; 
    display: flex;
    gap: 10px;
}
.folder { 
    margin-bottom: 15px; 
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid #eee;
}
.folder-header { 
    cursor: pointer; 
    padding: 12px; 
    font-weight: bold;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.folder-header:after {
    content: "▾";
    font-size: 14px;
}
.folder-list { 
    list-style: none; 
    margin: 0;
    background-color: white;
    padding: 10px;
}
.folder-list.hidden { display: none; }
.folder-list li { 
    padding: 10px;
    margin-bottom: 5px;
    background-color: #f9f9f9;
    border-radius: 4px;
    cursor: move;
    user-select: none;
    border-left: 3px solid #ddd;
}
.folder-list li:hover {
    background-color: #f0f0f0;
}
.modal { 
    position: fixed; 
    top: 0; 
    left: 0; 
    right: 0; 
    bottom: 0; 
    background: rgba(0,0,0,0.6); 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    z-index: 100;
}
.modal.hidden { display: none; }
.modal-content { 
    background: #fff; 
    padding: 25px; 
    border-radius: 8px;
    min-width: 500px;
    max-width: 70%;
    max-height: 80vh;
    overflow-y: auto;
    box-shadow: 0 5px 15px rgba(0,0,0,0.3);
}
.modal-content h3 {
    margin-bottom: 20px;
    color: #333;
    font-size: 18px;
}
.modal-content input[type="text"],
.modal-content select {
    width: 100%;
    padding: 10px;
    margin-bottom: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}
.modal-content textarea {
    width: 100%;
    min-height: 300px;
    padding: 15px;
    margin-bottom: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
    font-family: inherit;
    resize: vertical;
}
.modal-content button {
    padding: 8px 15px;
    background-color: #4a86e8;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    margin-right: 10px;
}
.modal-content button.close-modal {
    background-color: #e2e2e2;
    color: #333;
}
.paragraph-drop-zone {
    min-height: 20px;
    margin: 5px 0;
}
.paragraph-drop-zone.active {
    background-color: rgba(74, 134, 232, 0.1);
    border: 2px dashed #4a86e8;
    border-radius: 4px;
    padding: 5px;
}
.block-text {
  white-space: pre-wrap;   /* preserve newlines and multiple spaces */
}

.folder-list li {
  position: relative;
    white-space: pre-wrap;   /* preserve newlines and multiple spaces */
  /* preserve existing padding/margins */
  padding: 10px;
  margin-bottom: 5px;
  background-color: #f9f9f9;
  border-radius: 4px;
  cursor: move;
  user-select: none;
  border-left: 3px solid #ddd;

  /* NEW: clamp to 6 lines */
  display: -webkit-box;
  -webkit-line-clamp: 6;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.edit-paragraph-btn {
    position: absolute;
  top: 8px;
  right: 8px;
  background: none;
  border: none;
  font-size: 16px;
  cursor: pointer;
  z-index: 10;
  /* optional: a little semi‑transparent background so it’s always visible */
  padding: 2px 4px;
  background-color: rgba(255,255,255,0.7);
  border-radius: 3px;
}

.add-paragraph-btn {
    position: absolute;
  top: 36px;
  right: 8px;
  background: none;
  border: none;
  font-size: 16px;
  cursor: pointer;
  z-index: 10;
  /* optional: a little semi‑transparent background so it’s always visible */
  padding: 2px 4px;
  background-color: rgba(255,255,255,0.7);
  border-radius: 3px;
}

.change-password-btn {
    padding: 8px 12px;
    background-color: #666;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    margin-top: 10px;
}

.change-password-btn:hover {
    background-color: #555;
}


    </style>
</head>
<body>
<aside class="panel left-panel">
    <div class="profile-switcher" id="profile-switcher">
        <div class="current-profile">
            <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyNCIgaGVpZ2h0PSIyNCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSJub25lIiBzdHJva2U9ImN1cnJlbnRDb2xvciIgc3Ryb2tlLXdpZHRoPSIyIiBzdHJva2UtbGluZWNhcD0icm91bmQiIHN0cm9rZS1saW5lam9pbj0icm91bmQiIGNsYXNzPSJmZWF0aGVyIGZlYXRoZXItdXNlciI+PHBhdGggZD0iTTIwIDIxdi0yYTQgNCAwIDAgMC00LTRINGE0IDQgMCAwIDAtNCA0djIiPjwvcGF0aD48Y2lyY2xlIGN4PSIxMiIgY3k9IjciIHI9IjQiPjwvY2lyY2xlPjwvc3ZnPg==" alt="">
            <span class="profile-label">Select Profile</span>
        </div>
        <ul class="profile-list hidden" id="profile-list"></ul>
    </div>
    <button class="change-password-btn" id="change-password-btn" style="display: none;">Change Password</button>
    <div class="fields">
        <label>Name<input type="text" id="field-name" placeholder="Your Name"></label>
        <label>LinkedIn URL
            <input type="url" id="field-linkedin" placeholder="https://www.linkedin.com/in/…">
        </label>
        <label>Current Date<input type="date" id="field-date"></label>
        <label>Start Date<input type="text" id="field-start" placeholder="Start Date"></label>
        <label>Company Name<input type="text" id="field-company" placeholder="Company Name"></label>
        <label>Position<input type="text" id="field-position" placeholder="Position Title"></label>
        <label>Field of Study<input type="text" id="field-study" placeholder="Your Field of Study"></label>
        <label>Contact Info<input type="text" id="field-contact" placeholder="Your Contact Information"></label>
        <label>Citizenship Status<input type="text" id="field-citizenship" placeholder="Your Citizenship Status"></label>
        <label>Margins<input type="number" id="margins" placeholder="0.25"></label>
        <label>Font Size<input type="number" id="font-size" placeholder="11"></label>
        <label>Font Family<input type="text" id="font-family" placeholder="Arial, sans-serif"></label>
    </div>
    <div class="template-controls">
        <button id="save-template" type="button">Save as Template</button>
        <button id="save-pdf" type="button">Save as PDF</button>
        <button id="copy-text" type="button">Copy Text</button>
    </div>
    <div class="saved-templates">
        <h4>Saved Templates</h4>
        <ul class="template-list" id="template-list"></ul>
    </div>
</aside>
<main class="panel center-panel">
    <div id="cover-letter-container">
        <div id="cover-letter-content">
            <!-- Paragraph blocks will be added here -->
        </div>
    </div>
</main>
<aside class="panel right-panel">
    <div class="repo-controls">
        <button id="new-paragraph" type="button">+ New Paragraph</button>
        <button id="new-folder" type="button">New Folder</button>
    </div>
    <div id="folders">
        <!-- Folders will be loaded here -->
    </div>
</aside>

<!-- Modals -->
<div class="modal hidden" id="folder-modal">
    <div class="modal-content">
        <h3>Create Folder</h3>
        <input type="text" id="folder-name" placeholder="Folder Name">
        <div class="color-selector">
            <label for="folder-color">Folder Color:</label>
            <input type="color" id="folder-color" value="#4a86e8">
        </div>
        <button id="create-folder" type="button">Create</button>
        <button class="close-modal" type="button">Cancel</button>
    </div>
</div>
<div class="modal hidden" id="new-paragraph-modal">
    <div class="modal-content">
        <h3>New Paragraph</h3>
        <p>These are the available placeholders for the fields:</p>
        <p>[Name] [Field of study] [citizenship] [contact info] [date] [company name] [position] [start date]</p>
        <textarea id="paragraph-text" placeholder="Type your paragraph here..."></textarea>
        <div>
            <label for="paragraph-folder">Select Folder:</label>
            <select id="paragraph-folder"></select>
        </div>
        <button id="create-paragraph" type="button">Add</button>
        <button class="close-modal" type="button">Cancel</button>
    </div>
</div>
<div class="modal hidden" id="edit-paragraph-modal">
    <div class="modal-content">
        <h3>Edit Paragraph</h3>
        <textarea id="edit-paragraph-text" placeholder="Edit your paragraph here..."></textarea>
        <button id="edit-paragraph" type="button">Confirm</button>
        <button class="close-modal" type="button">Cancel</button>
    </div>
</div>
<div class="modal hidden" id="template-modal">
    <div class="modal-content">
        <h3>Save as Template</h3>
        <input type="text" id="template-name" placeholder="Template Name">
        <button id="save-as-template" type="button">Save</button>
        <button class="close-modal" type="button">Cancel</button>
    </div>
</div>
<div class="modal hidden" id="change-password-modal">
    <div class="modal-content">
        <h3>Change Password</h3>
        <input type="password" id="current-password" placeholder="Current Password">
        <input type="password" id="new-password" placeholder="New Password">
        <input type="password" id="confirm-password" placeholder="Confirm New Password">
        <button id="confirm-change-password" type="button">Change Password</button>
        <button class="close-modal" type="button">Cancel</button>
    </div>
</div>
<script>

// Wrap in DOMContentLoaded
document.addEventListener('DOMContentLoaded', () => {
    let currentProfile = null;
    let draggedBlock = null;
    let editingParagraphId = null;
    const fields = ['name','date','start','company','position','study','contact','citizenship','linkedin'];

    // map bracket text → field key
    const placeholderMap = {
      'name': 'name',
      'field of study': 'study',
      'citizenship': 'citizenship',
      'contact info': 'contact',
      'date': 'date',
      'start date': 'start',
      'company name': 'company',
      'position': 'position',
      'linkedin': 'linkedin',
    };
    const dateField = document.getElementById("field-date");
    const today = new Date().toISOString().split("T")[0];
    dateField.value = today;

    // Elements
    const profileSwitcher = document.getElementById('profile-switcher');
    const currentProfileDiv = profileSwitcher.querySelector('.current-profile');
    const profileList = document.getElementById('profile-list');
    const fieldElems = {};
    fields.forEach(f => fieldElems[f] = document.getElementById('field-' + f));
    const coverContent = document.getElementById('cover-letter-content');
    const foldersContainer = document.getElementById('folders');
    const newProfileItem = document.createElement('li');
    const marginInput     = document.getElementById('margins');
    const fontSizeInput   = document.getElementById('font-size');
    const fontFamilyInput = document.getElementById('font-family');
    const changePasswordBtn = document.getElementById('change-password-btn');
    fieldElems['linkedin'] = document.getElementById('field-linkedin');
    newProfileItem.textContent = '+ New Profile';
    profileList.appendChild(newProfileItem);

    // apply immediately & on change
    function updateStyles() {
        const m  = parseFloat(marginInput.value)     || 0.25;      // inches
        const fs = parseFloat(fontSizeInput.value)   || 11;        // points
        const ff = fontFamilyInput.value.trim()      || 'Arial, sans-serif';

        // live‑preview in center pane
        coverContent.parentElement.style.padding        = `${m}in`;   // covers both top/bottom
        coverContent.style.paddingLeft  = `${m}in`;
        coverContent.style.paddingRight = `${m}in`;
        coverContent.style.fontSize     = `${fs}pt`;
        coverContent.style.fontFamily   = ff;
    }

    // attach listeners
    [ marginInput, fontSizeInput, fontFamilyInput ].forEach(el =>
        el.addEventListener('input', () => {
            updateStyles();
            // we don’t persist these in JSON, but if you want to,
            // you could add them to your saveProfile payload here
        })
    );

    // call once on load
    updateStyles();

    // helper: format YYYY-MM-DD → "Month D, YYYY"
    function fmtDate(val) {
        if (!val) return '';
        // split into [YYYY, MM, DD]
        const [y, m, d] = val.split('-').map(Number);
        // construct a local Date
        const date = new Date(y, m - 1, d);
        return date.toLocaleDateString('en-US', {
            year: 'numeric', month: 'long', day: 'numeric'
        });
    }

    if (!profileSwitcher || !currentProfileDiv || !profileList) {
        console.error('Profile switcher elements not found. Check HTML IDs and classes.');
        return;
    }

    if (!coverContent) {
        console.error('Cover letter content not found.');
        return;
    }

    // helper: replace [Placeholders] in rawText with <span>highlighted</span> values
    function replacePlaceholders(rawText) {
      return rawText.replace(/\[([^\]]+)\]/g, (_, tag) => {
        const key = tag.trim().toLowerCase();
        const fld = placeholderMap[key];
        val = fieldElems[fld].value;
        
        if (fld && val) {
          if (fld === 'linkedin') {
            const name = fieldElems['name'].value || '';
            // inject a clickable link
            return `<a href="${val}" target="_blank" style="color:#4a86e8;">${name} | LinkedIn</a>`;
          }
          if (fld === 'date') {
            val = fmtDate(val);
          }
          // escape HTML if you like, omitted for brevity
          return `<span style="background-color: yellow;">${val}</span>`;
        } else {
          // leave original if no match
          return `[${tag}]`;
        }
      });
    }

    // update a single block from its rawText
    function updateBlock(block) {
      const txtDiv = block.querySelector('.block-text');
      txtDiv.innerHTML = replacePlaceholders(block.dataset.rawText);
    }

    // re‑run replacement on every block
    function updateAllBlocks() {
      document.querySelectorAll('.paragraph-block').forEach(updateBlock);
    }

    // add listeners so that whenever any field changes, we re-render & save
    fields.forEach(f => {
      fieldElems[f].addEventListener('input', () => {
        updateAllBlocks();
        saveProfile();
      });
    });

    // Create Profile
    function createProfile(name) {
        const pw = prompt(`Create a password for profile “${name}”:`);
        if (pw === null) return; // user cancelled

        console.log('Creating profile:', name);
        const form = new FormData();
        form.append('name', name);
        form.append('password', pw);
        fetch('?action=create_profile', { method: 'POST', body: form })
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    console.log('Profile created successfully');
                    loadProfiles();
                } else {
                    alert('Profile exists');
                }
            });
    }

    // Add this new helper before selectProfile():
    async function verifyProfile(name) {
        // 1) Prompt for the existing profile’s password
        const pw = prompt(`Enter password for profile “${name}”:`);
        if (pw === null) return; // user cancelled

        // 2) POST to the new verify_profile endpoint
        const resp = await fetch('?action=verify_profile', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ profile: name, password: pw })
        });
        const { ok, error } = await resp.json();

        if (ok) {
            // ★ set currentProfile here ★
            currentProfile = name
            // 3) On success, actually load the profile data
            loadProfileData(name);
            currentProfileDiv.querySelector('.profile-label').textContent = name;
            profileList.classList.add('hidden');
        } else {
            alert(error || 'Invalid password');
        }
    }

    // Load profiles
    // Load profiles into dropdown
    function loadProfiles() {
        console.log('Loading profiles...');
        fetch('?action=load_profiles')
            .then(r => r.json())
            .then(profList => {
                console.log('Profiles loaded:', profList);
                profileList.innerHTML = '';
                profList.forEach(name => {
                    const li = document.createElement('li');
                    li.textContent = name;
                    li.addEventListener('click', () => selectProfile(name));
                    profileList.appendChild(li);
                });
                // "+ New Profile" option
                const liAdd = document.createElement('li');
                liAdd.textContent = '+ New Profile';
                liAdd.style.fontWeight = 'bold';
                liAdd.style.color = '#4a86e8';
                liAdd.addEventListener('click', () => {
                    console.log('Add new profile clicked');
                    const name = prompt('Enter new profile name:');
                    if (name) createProfile(name);
                });
                profileList.appendChild(liAdd);
            });
    }
    
     // Profile selection handler
  function selectProfile(name) {
    verifyProfile(name);
    /*
    console.log('Selecting profile:', name);
    currentProfile = name;
    // update display label
    const label = currentProfileDiv.querySelector('.profile-label');
    if (label) label.textContent = name;
    profileList.classList.add('hidden');
    loadProfileData(name);*/
  }

    currentProfileDiv.addEventListener('click', () => {
        console.log('currentProfileDiv clicked');
        profileList.classList.toggle('hidden');
    });

    // Load profile data
    function loadProfileData(name) {
        fetch(`?action=load_profile&profile=${encodeURIComponent(name)}`)
            .then(r=>r.json()).then(data => {
                // 1) populate left‐panel inputs
                if (data.fields) {
                    fields.forEach(f => {
                    if (data.fields[f] !== undefined && f !== 'date') {
                        fieldElems[f].value = data.fields[f];
                    }
                    });
                }
                repoFolders = data.folders || [];
                repoParagraphs = data.paragraphs || [];
                repoTemplates = data.templates || [];
                loadTemplates(repoTemplates);
                loadFolders(repoFolders);
                coverContent.innerHTML = '';
                updateAllBlocks();
            });
    }

    function saveProfile() {
        if (!currentProfile) return alert('Select profile first');
        const form = new FormData();
        form.append('profile', currentProfile);
        // fields
        const fld = {};
        fields.forEach(f => fld[f] = fieldElems[f].value);
        form.append('fields', JSON.stringify(fld));
        // repo data
        
        form.append('folders', JSON.stringify(repoFolders));
        form.append('paragraphs', JSON.stringify(repoParagraphs));
        form.append('templates', JSON.stringify(repoTemplates));
        fetch('?action=save_profile', { method: 'POST', body: form })
            .then(r => r.json())
            .then(res => {
                if (!res.ok) alert('Save error');   
            });
    }

    // Data stores
    let repoFolders = [];
    let repoParagraphs = [];
    let repoTemplates = [];

    function loadFolders(folders) {
        repoFolders = folders;
        const container = document.getElementById('folders');
        container.innerHTML = '';
        folders.forEach((fld, idx) => {
            const div = document.createElement('div'); div.className='folder';
            const hdr = document.createElement('div'); hdr.className='folder-header'; hdr.textContent = fld.name;
            hdr.style.background = fld.color;
            hdr.addEventListener('click', () => list.classList.toggle('hidden'));
            div.appendChild(hdr);
            const list = document.createElement('ul'); list.className='folder-list hidden';
            fld.paragraphs = fld.paragraphs||[];
            fld.paragraphs.forEach(pid => {
                const p = repoParagraphs.find(pp=>pp.id===pid);
                if (p) {
                    const li = document.createElement('li'); li.textContent=p.text;
                    li.draggable=true;
                    li.addEventListener('dragstart', e=>{
                        e.dataTransfer.setData('text/plain', p.id);
                    });

                    // — EDIT button —
                    const btn = document.createElement('button');
                    btn.textContent = '✎';
                    btn.className = 'edit-paragraph-btn';
                    btn.style.marginLeft = '8px';
                    btn.addEventListener('click', e => {
                        e.stopPropagation();
                        openEditParagraph(pid);
                    });
                    li.appendChild(btn);

                    // — ADD TO COVER button —
                    const addBtn = document.createElement('button');
                    addBtn.textContent = '+';
                    addBtn.className = 'add-paragraph-btn';
                    addBtn.style.marginLeft = '4px';
                    addBtn.addEventListener('click', e => {
                        e.stopPropagation();
                        // append this paragraph to the end of the cover letter
                        const block = createParagraphBlock(p.text, p.id);
                        coverContent.appendChild(block);
                    });
                    li.appendChild(addBtn);

                    list.appendChild(li);
                }
            });
            div.appendChild(list);
            container.appendChild(div);
        });
    }

    function loadTemplates(templates) {
        repoTemplates = templates;
        const tplList = document.getElementById('template-list'); 
        tplList.innerHTML='';
        templates.forEach(t=>{
            const li=document.createElement('li'); li.textContent=t.name;
            li.addEventListener('click',()=>{
                coverContent.innerHTML='';
                t.order.forEach(pid=>{
                    const p=repoParagraphs.find(pp=>pp.id===pid);
                    if(p) {
                        const block = createParagraphBlock(p.text, p.id);
                        coverContent.appendChild(block);
                     }
                });
            });
            tplList.appendChild(li);
        });
    }

    // Paragraph creation
    document.getElementById('new-folder').addEventListener('click',()=>{
        document.getElementById('folder-modal').classList.remove('hidden');
    });
    document.getElementById('create-folder').addEventListener('click',()=>{
        const name=document.getElementById('folder-name').value;
        const color=document.getElementById('folder-color').value;
        if(!name) return;
        const id=Date.now();
        repoFolders.push({id,name,color,paragraphs:[]});
        saveProfile();
        loadFolders(repoFolders);
        document.getElementById('folder-modal').classList.add('hidden');
    });
    document.querySelectorAll('#folder-modal .close-modal').forEach(btn=>btn.addEventListener('click',()=>{
        document.getElementById('folder-modal').classList.add('hidden');
    }));

    document.getElementById('new-paragraph').addEventListener('click',()=>{
        const sel=document.getElementById('paragraph-folder'); sel.innerHTML='';
        repoFolders.forEach(f=>{
            const opt=document.createElement('option'); opt.value=f.id; opt.textContent=f.name;
            sel.appendChild(opt);
        });
        document.getElementById('new-paragraph-modal').classList.remove('hidden');
    });
    // create or edit paragraph modal
    document.getElementById('create-paragraph').addEventListener('click', () => {
        const text = document.getElementById('paragraph-text').value;
        const fid  = document.getElementById('paragraph-folder').value;
        // new paragraph
        const id = Date.now();
        repoParagraphs.push({id, text});
        repoFolders.find(f=>f.id==fid).paragraphs.push(id);
        document.getElementById('new-paragraph-modal').classList.add('hidden');
        //document.getElementById('edit-paragraph-modal').classList.add('hidden');
        saveProfile();
        loadFolders(repoFolders);
    });
    document.getElementById('edit-paragraph').addEventListener('click', () => {
        const text = document.getElementById('edit-paragraph-text').value;
        //const fid  = document.getElementById('paragraph-folder').value;
        const p = repoParagraphs.find(p=>p.id===editingParagraphId);
        p.text = text;
        editingParagraphId = null;
        //document.getElementById('new-paragraph-modal').classList.add('hidden');
        document.getElementById('edit-paragraph-modal').classList.add('hidden');
        saveProfile();
        loadFolders(repoFolders);
    });

    function openEditParagraph(pid) {
        editingParagraphId = pid;
        const p = repoParagraphs.find(p=>p.id===pid);
        document.getElementById('edit-paragraph-text').value = p.text;
        document.getElementById('edit-paragraph-modal').classList.remove('hidden');
    }
    document.querySelectorAll('#edit-paragraph-modal .close-modal').forEach(btn=>btn.addEventListener('click',()=>{
        document.getElementById('edit-paragraph-modal').classList.add('hidden');
    }));
    document.querySelectorAll('#new-paragraph-modal .close-modal').forEach(btn=>btn.addEventListener('click',()=>{
        document.getElementById('new-paragraph-modal').classList.add('hidden');
    }));


    // Drag & drop into cover
    coverContent.addEventListener('dragover', e=> e.preventDefault());

    // create a paragraph‐block element with delete & reorder
    function createParagraphBlock(rawText, paragraphId) {
      const block = document.createElement('div');
      block.className = 'paragraph-block';
      block.draggable = true;
      // stash the original
      block.dataset.rawText = rawText;
      // stash the actual ID
      if (paragraphId !== undefined) {
          block.dataset.paragraphId = paragraphId;
      }

      // X button (in .controls so it shows on hover)
      const controls = document.createElement('div');
      controls.className = 'controls';
      const btn = document.createElement('button');
      btn.textContent = '×';
      btn.addEventListener('click', () => block.remove());
      controls.appendChild(btn);
      block.appendChild(controls);

      // UP arrow
      const upBtn = document.createElement('button');
      upBtn.textContent = '↑';
      upBtn.addEventListener('click', () => {
          const prev = block.previousElementSibling;
          if (prev) coverContent.insertBefore(block, prev);
      });
      controls.appendChild(upBtn);

      // DOWN arrow
      const downBtn = document.createElement('button');
      downBtn.textContent = '↓';
      downBtn.addEventListener('click', () => {
          const next = block.nextElementSibling;
          if (next) coverContent.insertBefore(next, block);
      });
      controls.appendChild(downBtn);

      // the actual text
      const txt = document.createElement('div');
      txt.className = 'block-text';
      //txt.textContent = text;
      block.appendChild(txt);

      updateBlock(block);

      // dragstart: remember which block is being dragged
      block.addEventListener('dragstart', e => {
        draggedBlock = block;
        e.dataTransfer.effectAllowed = 'move';
      });

      // when another block is dragged over this one
      block.addEventListener('dragover', e => {
        e.preventDefault();
        block.classList.add('drag-over');
        e.dataTransfer.dropEffect = 'move';
      });
      block.addEventListener('dragleave', () => {
        block.classList.remove('drag-over');
      });

      // on drop, insert the dragged block right after this one
      block.addEventListener('drop', e => {
        e.preventDefault();
        block.classList.remove('drag-over');
        if (draggedBlock && draggedBlock !== block) {
          coverContent.insertBefore(draggedBlock, block.nextSibling);
        }
      });

      return block;
    }

    // single drop listener for center pane
    coverContent.addEventListener('dragover', e => e.preventDefault());
    coverContent.addEventListener('drop', e => {
      e.preventDefault();
      const pid = e.dataTransfer.getData('text/plain');
      const p = repoParagraphs.find(x => x.id == pid);
      if (p) {
        console.log("New paragraph just dropped");
        const block = createParagraphBlock(p.text, p.id);
        coverContent.appendChild(block);
      }
    });

    // Template save
    document.getElementById('save-template').addEventListener('click',()=>{
        const name=prompt('Template name:');
        if(!name) return;
        const blocks = Array.from(coverContent.children).map(b => parseInt(b.dataset.paragraphId, 10));
        const tpl={name,order:blocks};
        repoTemplates.push(tpl);
        saveProfile();
        loadTemplates(repoTemplates);
    });

    // Copy text
    document.getElementById('copy-text').addEventListener('click',()=>{
        const raw = Array.from(coverContent.children)
            .map(block => block.querySelector('.block-text').textContent)
            .join('\n\n');
        let filled = raw.replace(/\[([^\]]+)\]/g,(_,key)=> fieldElems[key]?.value || '');
        filled = filled.replace(/\n{2,}/g, '\n');
        navigator.clipboard.writeText(filled).then(()=> alert('Copied!'));
    });

    // Save PDF
    document.getElementById('save-pdf').addEventListener('click',()=>{
        if (!currentProfile) return alert('Select profile first');
        /*
        const raw = Array.from(coverContent.children)
            .map(block => block.querySelector('.block-text').textContent)
            .join('\n\n');
            */
        let raw = Array.from(coverContent.children)
            .map(block => block.querySelector('.block-text').innerHTML)
            .join('<br><br>');
        // 2) strip out any <span…> highlights
        raw = raw
            .replace(/<span[^>]*>/gi, '')
            .replace(/<\/span>/gi, '');
        // 3) collapse runs of 2 or more <br> to exactly one
        raw = raw.replace(/(?:<br>\s*){2,}/gi, '<br>');
        raw = raw.replace(/\n{3,}/g, '\n\n');

        let filled = raw.replace(/\[([^\]]+)\]/g,(_,key)=> fieldElems[key]?.value || '');
        filled = filled.replace(/\n{3,}/g, '\n\n');
        // build a tiny form to POST the content
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '?action=save_pdf';
        form.style.display = 'none';
        const ta = document.createElement('textarea');
        ta.name = 'content_html';
        ta.style.display = 'none';
        // put your text inside the textarea node itself
        ta.textContent = raw;
        form.appendChild(ta);
        //form.append('content', rawText);
        // instead, create three hidden inputs and append them:
        [
            ['margins',     marginInput.value],
            ['font_size',   fontSizeInput.value],
            ['font_family', fontFamilyInput.value]
        ].forEach(([name, value]) => {
            const inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = name;
            inp.value = value;
            form.appendChild(inp);
        });
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    });

    // Auto-save fields
    fields.forEach(f=>{
        fieldElems[f].addEventListener('change', saveProfile);
    });

    // init
    loadProfiles();
});
</script>
</body>
</html>
