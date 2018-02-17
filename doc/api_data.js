define({ "api": [
  {
    "type": "get",
    "url": "/api.php",
    "title": "01. Basic Information",
    "name": "Info",
    "group": "API",
    "description": "<p>Each API call will return the result in JSON format. There are 2 objects, &quot;status&quot; and &quot;data&quot;.</p> <p>The &quot;status&quot; object returns &quot;ok&quot; when the transaction is successful and &quot;error&quot; on failure.</p> <p>The &quot;data&quot; object returns the requested data, as sub-objects.</p> <p>The parameters must be sent either as POST['data'], json encoded array or independently as GET.</p>",
    "success": {
      "fields": {
        "Success 200": [
          {
            "group": "Success 200",
            "type": "String",
            "optional": false,
            "field": "status",
            "description": "<p>&quot;ok&quot;</p>"
          },
          {
            "group": "Success 200",
            "type": "String",
            "optional": false,
            "field": "data",
            "description": "<p>The data provided by the api will be under this object.</p>"
          }
        ]
      },
      "examples": [
        {
          "title": "Success-Response:",
          "content": "{\n  \"status\":\"ok\",\n  \"data\":{\n     \"obj1\":\"val1\",\n     \"obj2\":\"val2\",\n     \"obj3\":{\n        \"obj4\":\"val4\",\n        \"obj5\":\"val5\"\n     }\n  }\n}",
          "type": "json"
        }
      ]
    },
    "error": {
      "fields": {
        "Error 4xx": [
          {
            "group": "Error 4xx",
            "type": "String",
            "optional": false,
            "field": "status",
            "description": "<p>&quot;error&quot;</p>"
          },
          {
            "group": "Error 4xx",
            "type": "String",
            "optional": false,
            "field": "result",
            "description": "<p>Information regarding the error</p>"
          }
        ]
      },
      "examples": [
        {
          "title": "Error-Response:",
          "content": "{\n  \"status\": \"error\",\n  \"data\": \"The requested action could not be completed.\"\n}",
          "type": "json"
        }
      ]
    },
    "version": "0.0.0",
    "filename": "./api.php",
    "groupTitle": "API"
  },
  {
    "type": "get",
    "url": "/api.php?q=base58",
    "title": "03. base58",
    "name": "base58",
    "group": "API",
    "description": "<p>Converts a string to base58.</p>",
    "parameter": {
      "fields": {
        "Parameter": [
          {
            "group": "Parameter",
            "type": "string",
            "optional": false,
            "field": "data",
            "description": "<p>Input string</p>"
          }
        ]
      }
    },
    "success": {
      "fields": {
        "Success 200": [
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "data",
            "description": "<p>Output string</p>"
          }
        ]
      }
    },
    "version": "0.0.0",
    "filename": "./api.php",
    "groupTitle": "API"
  },
  {
    "type": "get",
    "url": "/api.php?q=currentBlock",
    "title": "10. currentBlock",
    "name": "currentBlock",
    "group": "API",
    "description": "<p>Returns the current block.</p>",
    "success": {
      "fields": {
        "Success 200": [
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "id",
            "description": "<p>Blocks id</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "generator",
            "description": "<p>Block Generator</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "height",
            "description": "<p>Height</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "date",
            "description": "<p>Block's date in UNIX TIMESTAMP format</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "nonce",
            "description": "<p>Mining nonce</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "signature",
            "description": "<p>Signature signed by the generator</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "difficulty",
            "description": "<p>The base target / difficulty</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "argon",
            "description": "<p>Mining argon hash</p>"
          }
        ]
      }
    },
    "version": "0.0.0",
    "filename": "./api.php",
    "groupTitle": "API"
  },
  {
    "type": "get",
    "url": "/api.php?q=generateAccount",
    "title": "09. generateAccount",
    "name": "generateAccount",
    "group": "API",
    "description": "<p>Generates a new account. This function should only be used when the node is on the same host or over a really secure network.</p>",
    "success": {
      "fields": {
        "Success 200": [
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "address",
            "description": "<p>Account address</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "public_key",
            "description": "<p>Public key</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "private_key",
            "description": "<p>Private key</p>"
          }
        ]
      }
    },
    "version": "0.0.0",
    "filename": "./api.php",
    "groupTitle": "API"
  },
  {
    "type": "get",
    "url": "/api.php?q=getAddress",
    "title": "02. getAddress",
    "name": "getAddress",
    "group": "API",
    "description": "<p>Converts the public key to an ARO address.</p>",
    "parameter": {
      "fields": {
        "Parameter": [
          {
            "group": "Parameter",
            "type": "string",
            "optional": false,
            "field": "public_key",
            "description": "<p>The public key</p>"
          }
        ]
      }
    },
    "success": {
      "fields": {
        "Success 200": [
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "data",
            "description": "<p>Contains the address</p>"
          }
        ]
      }
    },
    "version": "0.0.0",
    "filename": "./api.php",
    "groupTitle": "API"
  },
  {
    "type": "get",
    "url": "/api.php?q=getBalance",
    "title": "04. getBalance",
    "name": "getBalance",
    "group": "API",
    "description": "<p>Returns the balance of a specific account or public key.</p>",
    "parameter": {
      "fields": {
        "Parameter": [
          {
            "group": "Parameter",
            "type": "string",
            "optional": true,
            "field": "public_key",
            "description": "<p>Public key</p>"
          },
          {
            "group": "Parameter",
            "type": "string",
            "optional": true,
            "field": "account",
            "description": "<p>Account id / address</p>"
          }
        ]
      }
    },
    "success": {
      "fields": {
        "Success 200": [
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "data",
            "description": "<p>The ARO balance</p>"
          }
        ]
      }
    },
    "version": "0.0.0",
    "filename": "./api.php",
    "groupTitle": "API"
  },
  {
    "type": "get",
    "url": "/api.php?q=getBlock",
    "title": "11. getBlock",
    "name": "getBlock",
    "group": "API",
    "description": "<p>Returns the block.</p>",
    "parameter": {
      "fields": {
        "Parameter": [
          {
            "group": "Parameter",
            "type": "numeric",
            "optional": false,
            "field": "height",
            "description": "<p>Block Height</p>"
          }
        ]
      }
    },
    "success": {
      "fields": {
        "Success 200": [
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "id",
            "description": "<p>Block id</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "generator",
            "description": "<p>Block Generator</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "height",
            "description": "<p>Height</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "date",
            "description": "<p>Block's date in UNIX TIMESTAMP format</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "nonce",
            "description": "<p>Mining nonce</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "signature",
            "description": "<p>Signature signed by the generator</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "difficulty",
            "description": "<p>The base target / difficulty</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "argon",
            "description": "<p>Mining argon hash</p>"
          }
        ]
      }
    },
    "version": "0.0.0",
    "filename": "./api.php",
    "groupTitle": "API"
  },
  {
    "type": "get",
    "url": "/api.php?q=getBlockTransactions",
    "title": "12. getBlockTransactions",
    "name": "getBlockTransactions",
    "group": "API",
    "description": "<p>Returns the transactions of a specific block.</p>",
    "parameter": {
      "fields": {
        "Parameter": [
          {
            "group": "Parameter",
            "type": "numeric",
            "optional": true,
            "field": "height",
            "description": "<p>Block Height</p>"
          },
          {
            "group": "Parameter",
            "type": "string",
            "optional": true,
            "field": "block",
            "description": "<p>Block id</p>"
          }
        ]
      }
    },
    "success": {
      "fields": {
        "Success 200": [
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "block",
            "description": "<p>Block ID</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "confirmation",
            "description": "<p>Number of confirmations</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "date",
            "description": "<p>Transaction's date in UNIX TIMESTAMP format</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "dst",
            "description": "<p>Transaction destination</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "fee",
            "description": "<p>The transaction's fee</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "height",
            "description": "<p>Block height</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "id",
            "description": "<p>Transaction ID/HASH</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "message",
            "description": "<p>Transaction's message</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "signature",
            "description": "<p>Transaction's signature</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "public_key",
            "description": "<p>Account's public_key</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "src",
            "description": "<p>Sender's address</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "type",
            "description": "<p>&quot;debit&quot;, &quot;credit&quot; or &quot;mempool&quot;</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "val",
            "description": "<p>Transaction value</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "version",
            "description": "<p>Transaction version</p>"
          }
        ]
      }
    },
    "version": "0.0.0",
    "filename": "./api.php",
    "groupTitle": "API"
  },
  {
    "type": "get",
    "url": "/api.php?q=getPendingBalance",
    "title": "05. getPendingBalance",
    "name": "getPendingBalance",
    "group": "API",
    "description": "<p>Returns the pending balance, which includes pending transactions, of a specific account or public key.</p>",
    "parameter": {
      "fields": {
        "Parameter": [
          {
            "group": "Parameter",
            "type": "string",
            "optional": true,
            "field": "public_key",
            "description": "<p>Public key</p>"
          },
          {
            "group": "Parameter",
            "type": "string",
            "optional": true,
            "field": "account",
            "description": "<p>Account id / address</p>"
          }
        ]
      }
    },
    "success": {
      "fields": {
        "Success 200": [
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "data",
            "description": "<p>The ARO balance</p>"
          }
        ]
      }
    },
    "version": "0.0.0",
    "filename": "./api.php",
    "groupTitle": "API"
  },
  {
    "type": "get",
    "url": "/api.php?q=getPublicKey",
    "title": "08. getPublicKey",
    "name": "getPublicKey",
    "group": "API",
    "description": "<p>Returns the public key of a specific account.</p>",
    "parameter": {
      "fields": {
        "Parameter": [
          {
            "group": "Parameter",
            "type": "string",
            "optional": false,
            "field": "account",
            "description": "<p>Account id / address</p>"
          }
        ]
      }
    },
    "success": {
      "fields": {
        "Success 200": [
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "data",
            "description": "<p>The public key</p>"
          }
        ]
      }
    },
    "version": "0.0.0",
    "filename": "./api.php",
    "groupTitle": "API"
  },
  {
    "type": "get",
    "url": "/api.php?q=getTransaction",
    "title": "07. getTransaction",
    "name": "getTransaction",
    "group": "API",
    "description": "<p>Returns one transaction.</p>",
    "parameter": {
      "fields": {
        "Parameter": [
          {
            "group": "Parameter",
            "type": "string",
            "optional": false,
            "field": "transaction",
            "description": "<p>Transaction ID</p>"
          }
        ]
      }
    },
    "success": {
      "fields": {
        "Success 200": [
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "block",
            "description": "<p>Block ID</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "confirmation",
            "description": "<p>Number of confirmations</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "date",
            "description": "<p>Transaction's date in UNIX TIMESTAMP format</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "dst",
            "description": "<p>Transaction destination</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "fee",
            "description": "<p>The transaction's fee</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "height",
            "description": "<p>Block height</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "id",
            "description": "<p>Transaction ID/HASH</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "message",
            "description": "<p>Transaction's message</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "signature",
            "description": "<p>Transaction's signature</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "public_key",
            "description": "<p>Account's public_key</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "src",
            "description": "<p>Sender's address</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "type",
            "description": "<p>&quot;debit&quot;, &quot;credit&quot; or &quot;mempool&quot;</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "val",
            "description": "<p>Transaction value</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "version",
            "description": "<p>Transaction version</p>"
          }
        ]
      }
    },
    "version": "0.0.0",
    "filename": "./api.php",
    "groupTitle": "API"
  },
  {
    "type": "get",
    "url": "/api.php?q=getTransactions",
    "title": "06. getTransactions",
    "name": "getTransactions",
    "group": "API",
    "description": "<p>Returns the latest transactions of an account.</p>",
    "parameter": {
      "fields": {
        "Parameter": [
          {
            "group": "Parameter",
            "type": "string",
            "optional": true,
            "field": "public_key",
            "description": "<p>Public key</p>"
          },
          {
            "group": "Parameter",
            "type": "string",
            "optional": true,
            "field": "account",
            "description": "<p>Account id / address</p>"
          },
          {
            "group": "Parameter",
            "type": "numeric",
            "optional": true,
            "field": "limit",
            "description": "<p>Number of confirmed transactions, max 1000, min 1</p>"
          }
        ]
      }
    },
    "success": {
      "fields": {
        "Success 200": [
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "block",
            "description": "<p>Block ID</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "confirmation",
            "description": "<p>Number of confirmations</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "date",
            "description": "<p>Transaction's date in UNIX TIMESTAMP format</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "dst",
            "description": "<p>Transaction destination</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "fee",
            "description": "<p>The transaction's fee</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "height",
            "description": "<p>Block height</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "id",
            "description": "<p>Transaction ID/HASH</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "message",
            "description": "<p>Transaction's message</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "signature",
            "description": "<p>Transaction's signature</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "public_key",
            "description": "<p>Account's public_key</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "src",
            "description": "<p>Sender's address</p>"
          },
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "type",
            "description": "<p>&quot;debit&quot;, &quot;credit&quot; or &quot;mempool&quot;</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "val",
            "description": "<p>Transaction value</p>"
          },
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "version",
            "description": "<p>Transaction version</p>"
          }
        ]
      }
    },
    "version": "0.0.0",
    "filename": "./api.php",
    "groupTitle": "API"
  },
  {
    "type": "get",
    "url": "/api.php?q=mempoolSize",
    "title": "15. mempoolSize",
    "name": "mempoolSize",
    "group": "API",
    "description": "<p>Returns the number of transactions in mempool.</p>",
    "success": {
      "fields": {
        "Success 200": [
          {
            "group": "Success 200",
            "type": "numeric",
            "optional": false,
            "field": "data",
            "description": "<p>Number of mempool transactions</p>"
          }
        ]
      }
    },
    "version": "0.0.0",
    "filename": "./api.php",
    "groupTitle": "API"
  },
  {
    "type": "get",
    "url": "/api.php?q=send",
    "title": "14. send",
    "name": "send",
    "group": "API",
    "description": "<p>Sends a transaction.</p>",
    "parameter": {
      "fields": {
        "Parameter": [
          {
            "group": "Parameter",
            "type": "numeric",
            "optional": false,
            "field": "val",
            "description": "<p>Transaction value (without fees)</p>"
          },
          {
            "group": "Parameter",
            "type": "string",
            "optional": false,
            "field": "dst",
            "description": "<p>Destination address</p>"
          },
          {
            "group": "Parameter",
            "type": "string",
            "optional": false,
            "field": "public_key",
            "description": "<p>Sender's public key</p>"
          },
          {
            "group": "Parameter",
            "type": "string",
            "optional": true,
            "field": "signature",
            "description": "<p>Transaction signature. It's recommended that the transaction is signed before being sent to the node to avoid sending your private key to the node.</p>"
          },
          {
            "group": "Parameter",
            "type": "string",
            "optional": true,
            "field": "private_key",
            "description": "<p>Sender's private key. Only to be used when the transaction is not signed locally.</p>"
          },
          {
            "group": "Parameter",
            "type": "numeric",
            "optional": true,
            "field": "date",
            "description": "<p>Transaction's date in UNIX TIMESTAMP format. Requried when the transaction is pre-signed.</p>"
          },
          {
            "group": "Parameter",
            "type": "string",
            "optional": true,
            "field": "message",
            "description": "<p>A message to be included with the transaction. Maximum 128 chars.</p>"
          },
          {
            "group": "Parameter",
            "type": "numeric",
            "optional": true,
            "field": "version",
            "description": "<p>The version of the transaction. 1 to send coins.</p>"
          }
        ]
      }
    },
    "success": {
      "fields": {
        "Success 200": [
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "data",
            "description": "<p>Transaction id</p>"
          }
        ]
      }
    },
    "version": "0.0.0",
    "filename": "./api.php",
    "groupTitle": "API"
  },
  {
    "type": "get",
    "url": "/api.php?q=version",
    "title": "13. version",
    "name": "version",
    "group": "API",
    "description": "<p>Returns the node's version.</p>",
    "success": {
      "fields": {
        "Success 200": [
          {
            "group": "Success 200",
            "type": "string",
            "optional": false,
            "field": "data",
            "description": "<p>Version</p>"
          }
        ]
      }
    },
    "version": "0.0.0",
    "filename": "./api.php",
    "groupTitle": "API"
  },
  {
    "success": {
      "fields": {
        "Success 200": [
          {
            "group": "Success 200",
            "optional": false,
            "field": "varname1",
            "description": "<p>No type.</p>"
          },
          {
            "group": "Success 200",
            "type": "String",
            "optional": false,
            "field": "varname2",
            "description": "<p>With type.</p>"
          }
        ]
      }
    },
    "type": "",
    "url": "",
    "version": "0.0.0",
    "filename": "./doc/main.js",
    "group": "_github_node_doc_main_js",
    "groupTitle": "_github_node_doc_main_js",
    "name": ""
  }
] });
