/**
 * Order catcher for the checkout page.
 * Paste this into Extensions > Apps Script inside your Google Sheet, then deploy as a Web app.
 *
 * The page talks to this script with a <script> tag (JSONP), because browsers block a
 * normal cross-site fetch to Google Apps Script. That is why doGet does all the work.
 */

const NOTIFY_EMAIL = 'suneprinsloo426@gmail.com';  // where new orders get emailed
const SHEET_NAME   = 'Orders';                      // the tab orders are written to (created automatically)

function doGet(e)  { return handle(e); }
function doPost(e) { return handle(e); }

function handle(e) {
  const p = (e && e.parameter) || {};
  const send = (obj) => reply(obj, p.callback);

  try {
    // Spam trap: bots fill the hidden field, people don't
    if (p.website) return send({ ok: true });

    const lat = parseFloat(p.lat);
    const lng = parseFloat(p.lng);
    if (!isFinite(lat) || !isFinite(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
      return send({ ok: false, error: 'Location is required. Please allow location access and try again.' });
    }

    // Preview for the form: location -> address
    if (p.action === 'lookup') {
      const address = lookupAddress(lat, lng);
      return address
        ? send({ ok: true, address: address })
        : send({ ok: false, error: 'No street address found for this spot.' });
    }

    // Final submit
    const first   = clean(p.first_name, 60);
    const surname = clean(p.surname, 60);
    if (!first || !surname) return send({ ok: false, error: 'Please fill in every field.' });

    // The address comes from our own lookup, never from the browser
    const address  = lookupAddress(lat, lng) || '(no street address found)';
    const accuracy = parseInt(p.accuracy, 10);
    const mapLink  = 'https://www.google.com/maps?q=' + lat.toFixed(6) + ',' + lng.toFixed(6);

    const lock = LockService.getScriptLock();
    lock.waitLock(10000);
    try {
      const sheet = getSheet();
      sheet.appendRow([
        new Date(), safe(first), safe(surname), safe(address),
        lat.toFixed(6), lng.toFixed(6), isFinite(accuracy) ? accuracy : '', mapLink
      ]);
    } finally {
      lock.releaseLock();
    }

    MailApp.sendEmail(
      NOTIFY_EMAIL,
      'New delivery details',
      'New delivery details\n\n' +
      'Name:     ' + first + ' ' + surname + '\n' +
      'Address:  ' + address + '\n' +
      'Location: ' + mapLink + (isFinite(accuracy) ? ' (accurate to about ' + accuracy + ' m)' : '') + '\n'
    );

    return send({ ok: true });
  } catch (err) {
    console.error(err);
    return send({ ok: false, error: 'Something went wrong. Please try again.' });
  }
}

// Google's own geocoder (built in, free within Google's daily limits)
function lookupAddress(lat, lng) {
  try {
    const res = Maps.newGeocoder().setLanguage('en').reverseGeocode(lat, lng);
    if (res.status === 'OK' && res.results.length) return res.results[0].formatted_address;
  } catch (err) {
    console.error(err);
  }
  return '';
}

function getSheet() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let sheet = ss.getSheetByName(SHEET_NAME);
  if (!sheet) {
    sheet = ss.insertSheet(SHEET_NAME);
    sheet.appendRow(['Received', 'First name', 'Surname', 'Address', 'Lat', 'Lng', 'Accuracy (m)', 'Map link']);
    sheet.setFrozenRows(1);
  }
  return sheet;
}

function clean(value, max) {
  return String(value || '').replace(/[\r\n\t]+/g, ' ').trim().slice(0, max);
}

// Stop spreadsheet formula injection
function safe(value) {
  return /^[=+\-@]/.test(value) ? "'" + value : value;
}

// Reply as JSONP when the page asks for it (callback=...), plain JSON otherwise
function reply(obj, callback) {
  const json = JSON.stringify(obj);
  if (callback && /^[A-Za-z_$][A-Za-z0-9_$]*$/.test(callback)) {
    return ContentService.createTextOutput(callback + '(' + json + ')')
      .setMimeType(ContentService.MimeType.JAVASCRIPT);
  }
  return ContentService.createTextOutput(json).setMimeType(ContentService.MimeType.JSON);
}
